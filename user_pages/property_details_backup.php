<?php
include '../connection.php'; // Your database connection
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
    // Fetch property details (include ViewsCount)
    $property_sql = "
        SELECT
            p.property_ID, p.StreetAddress, p.City, p.Province, p.Barangay, p.PropertyType,
            p.YearBuilt, p.SquareFootage, p.LotSize, p.Bedrooms, p.Bathrooms,
            p.ListingPrice, p.Status, p.ListingDate, p.ListingDescription,
            p.ParkingType, p.MLSNumber, p.approval_status, COALESCE(p.ViewsCount,0) AS ViewsCount
        FROM property p
        WHERE p.property_ID = ? AND p.approval_status = 'approved'
    ";

    $stmt = $conn->prepare($property_sql);
    $stmt->bind_param("i", $property_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $property_data = $result->fetch_assoc();

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

        // Fetch agent or admin information (whoever created the listing)
        $agent_sql = "
            SELECT
                a.first_name, a.last_name, a.phone_number, a.email,
                ai.specialization, ai.profile_picture_url,
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
            
            // If it's an admin, prefer admin profile picture and set specialization
            if ($agent_info['user_role'] === 'admin') {
                $agent_info['profile_picture_url'] = $agent_info['admin_profile_picture_url'];
                $agent_info['specialization'] = 'Licensed Real Estate Admin';
            }
        }
    } else {
        $error_message = 'Property not found or no longer available.';
    }

    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $property_data ? htmlspecialchars($property_data['StreetAddress']) : 'Property Details'; ?> - Prestige Properties</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-gold: #d4af37;
            --background-color: #f8f9fa;
            --card-bg-color: #ffffff;
            --border-color: #e9ecef;
            --text-primary: #2c3e50;
            --text-secondary: #546e7a;
            --text-muted: #6c757d;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.12);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.15);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; 
            background-color: var(--background-color);
            color: var(--text-primary);
            line-height: 1.6;
            font-size: 15px;
        }

        /* Main Container */
        main.property-container {
            max-width: 100%;
            padding: 0;
            margin: 0;
            background: var(--background-color);
        }

        .content-wrapper {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1.5rem 3rem;
        }

        /* Breadcrumb */
        .breadcrumb-custom {
            background: white;
            padding: 1rem 0;
            margin: 0;
            font-size: 0.875rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .breadcrumb-custom .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }
        
        .breadcrumb-custom a {
            color: var(--secondary-color);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .breadcrumb-custom a:hover {
            text-decoration: underline;
            color: #a08636;
        }

    /* Auto Slideshow Gallery */
        .gallery-section {
            width: 100%;
            margin-bottom: 0;
            position: relative;
            background: #000;
        }

        .slideshow-container {
            position: relative;
            width: 100%;
            height: 500px;
            overflow: hidden;
        }

        .slide {
            display: none;
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
        }

        .slide.active {
            display: block;
            opacity: 1;
        }

        .slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            cursor: pointer;
        }

        /* Slideshow Navigation */
        .slideshow-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-color);
            transition: var(--transition-smooth);
            z-index: 10;
        }

        .slideshow-nav:hover {
            background: white;
            transform: translateY(-50%) scale(1.1);
            box-shadow: var(--shadow-md);
        }

        .slideshow-nav.prev {
            left: 1.5rem;
        }

        .slideshow-nav.next {
            right: 1.5rem;
        }

        /* Slideshow Controls */
        .slideshow-controls {
            position: absolute;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 0.5rem;
            z-index: 10;
        }

        .slideshow-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            transition: var(--transition-smooth);
            border: 2px solid transparent;
        }

        .slideshow-dot.active {
            background: white;
            border-color: var(--secondary-color);
            transform: scale(1.2);
        }

        .slideshow-dot:hover {
            background: rgba(255, 255, 255, 0.8);
            transform: scale(1.1);
        }

        /* Gallery Info Overlay */
        .gallery-info {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 0.75rem 1.25rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            z-index: 10;
        }

        /* Play/Pause Button */
        .slideshow-play-pause {
            position: absolute;
            top: 1.5rem;
            left: 1.5rem;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            border-radius: 50%;
            width: 45px;
            height: 45px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: var(--transition-smooth);
            z-index: 10;
        }

        .slideshow-play-pause:hover {
            background: rgba(0, 0, 0, 0.9);
            transform: scale(1.1);
        }

        /* View Gallery Button */
        .view-gallery-btn {
            position: absolute;
            bottom: 2rem;
            right: 2rem;
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: var(--transition-smooth);
            z-index: 10;
        }

        .view-gallery-btn:hover {
            background: var(--accent-gold);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Property Header */
        .property-header {
            margin-bottom: 2.5rem;
            background: var(--card-bg-color);
            border-radius: 0;
            padding: 2.5rem 0 2rem;
            box-shadow: none;
            border: none;
            border-bottom: 1px solid var(--border-color);
        }

        .property-title-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .property-price {
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--secondary-color);
            line-height: 1.2;
            margin-bottom: 0.75rem;
            letter-spacing: -0.5px;
        }

        .property-address {
            font-size: 1.125rem;
            color: var(--text-primary);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .property-address i {
            display: none;
        }
        
        .property-meta {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            flex-wrap: wrap;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        
        .property-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-weight: 500;
        }

        .property-meta span strong {
            color: var(--primary-color);
            font-weight: 700;
        }

        .property-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .action-button {
            min-width: 44px;
            height: 44px;
            border-radius: 10px;
            background: white;
            border: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition-smooth);
            font-size: 1.15rem;
            color: var(--text-secondary);
            padding: 0 0.75rem;
        }

        .action-button:hover {
            border-color: var(--secondary-color);
            background: rgba(188, 158, 66, 0.05);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .like-button.liked {
            background: rgba(188, 158, 66, 0.1);
            border-color: var(--secondary-color);
        }

        .like-button.liked i {
            color: var(--secondary-color);
        }

        .share-button:hover i {
            color: var(--secondary-color);
        }

        #viewsBadge {
            min-width: auto;
            padding: 0.5rem 0.875rem;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--text-secondary);
            border-color: var(--border-color);
        }

        #viewsBadge i {
            font-size: 1rem;
        }

        .property-specs {
            display: flex;
            gap: 2.5rem;
            padding: 1.75rem 0 0;
            background: transparent;
            border-radius: 0;
            border: none;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }

        .spec-item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.35rem;
            text-align: left;
            position: relative;
        }

        .spec-item:last-child {
            border-right: none;
        }

        .spec-item::after {
            display: none;
        }

        .spec-icon {
            display: none;
        }

        .spec-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-color);
            line-height: 1;
        }

        .spec-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .spec-divider {
            display: none;
        }

        /* Detail Cards */
        .detail-card {
            background-color: var(--card-bg-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition-smooth);
        }

        .detail-card:hover {
            box-shadow: var(--shadow-md);
            border-color: rgba(188, 158, 66, 0.2);
        }

        .detail-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }

        .detail-card h3 i {
            width: auto;
            height: auto;
            background: transparent;
            color: var(--secondary-color);
            border-radius: 0;
            display: inline;
            font-size: 1.5rem;
        }

        .description-text {
            font-size: 1rem;
            line-height: 1.75;
            color: var(--text-secondary);
            text-align: left;
        }

        /* Facts Grid */
        .facts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.75rem 3rem;
        }

        .fact-item {
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 1rem 0;
            background: transparent;
            border-radius: 0;
            border: none;
            border-bottom: 1px solid var(--border-color);
            transition: none;
        }

        .fact-item:hover {
            background: rgba(188, 158, 66, 0.02);
        }

        .fact-label {
            font-size: 0.95rem;
            color: var(--text-secondary);
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0;
        }

        .fact-value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        /* Amenities */
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .amenity-item {
            background: rgba(188, 158, 66, 0.03);
            border: 1px solid rgba(188, 158, 66, 0.15);
            border-radius: 8px;
            padding: 0.875rem 1rem;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: var(--transition-smooth);
            color: var(--text-primary);
        }

        .amenity-item:hover {
            background: rgba(188, 158, 66, 0.08);
            border-color: rgba(188, 158, 66, 0.3);
            transform: translateX(4px);
        }

        .amenity-item::before {
            content: "✓";
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--accent-gold) 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(188, 158, 66, 0.25);
        }

        /* Agent Card */
        .action-panel {
            position: sticky;
            top: 100px;
        }

        .agent-card {
            background: linear-gradient(145deg, #ffffff 0%, #fafafa 100%);
            color: var(--text-primary);
            border-radius: 12px;
            padding: 0;
            text-align: left;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }

        .agent-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary-color) 0%, var(--accent-gold) 100%);
        }

        .agent-card-content {
            position: relative;
            z-index: 2;
            padding: 2rem;
        }

        .agent-image-wrapper {
            margin-bottom: 1.5rem;
            position: relative;
            display: inline-block;
        }

        .agent-contact-img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: var(--shadow-md);
        }

        .agent-badge {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--accent-gold) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid white;
            box-shadow: var(--shadow-sm);
        }
        
        .agent-badge i {
            font-size: 0.8rem;
            color: white;
        }

        .agent-card h5 {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
            font-weight: 700;
        }

        .agent-name {
            font-size: 1.65rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
            line-height: 1.2;
        }

        .agent-specialization {
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 2rem;
            line-height: 1.5;
        }

        .agent-actions {
            display: grid;
            gap: 0.875rem;
        }

        .agent-btn {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition-smooth);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.625rem;
            cursor: pointer;
        }

        .agent-btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--accent-gold) 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        }

        .agent-btn-primary:hover {
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--secondary-color) 100%);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(188, 158, 66, 0.4);
        }

        .agent-btn-secondary {
            background: white;
            color: var(--secondary-color);
            border: 2px solid var(--secondary-color);
        }

        .agent-btn-secondary:hover {
            background: rgba(188, 158, 66, 0.08);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .sold-notice {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
        }
        
        .agent-contact-info {
            padding: 1.5rem 2rem;
            background: #f9f9f9;
            border-top: 1px solid var(--border-color);
        }

        .agent-contact-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 1rem;
            font-weight: 700;
            display: block;
        }
        
        .agent-contact-info a {
            color: var(--text-secondary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            font-size: 0.95rem;
            font-weight: 500;
            transition: var(--transition-smooth);
        }

        .agent-contact-info a i {
            width: 36px;
            height: 36px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary-color);
            font-size: 1rem;
            box-shadow: var(--shadow-sm);
        }
        
        .agent-contact-info a:hover {
            color: var(--secondary-color);
            transform: translateX(4px);
        }

        .agent-contact-info a:hover i {
            background: var(--secondary-color);
            color: white;
        }

        /* Property Status Badge */
        .property-badge {
            display: none;
        }

        /* Enhanced Fullscreen Lightbox Gallery */
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.98);
            z-index: 9999;
            animation: fadeIn 0.4s ease;
        }

        .lightbox.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from { 
                opacity: 0;
                backdrop-filter: blur(0);
            }
            to { 
                opacity: 1;
                backdrop-filter: blur(2px);
            }
        }

        .lightbox-content {
            max-width: 90%;
            max-height: 90vh;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .lightbox-image {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
            box-shadow: 0 20px 60px rgba(0,0,0,0.7);
            border-radius: 8px;
            transition: transform 0.3s ease;
        }

        .lightbox-image:hover {
            transform: scale(1.02);
        }

        /* Enhanced Lightbox Controls */
        .lightbox-close {
            position: absolute;
            top: 2rem;
            right: 2rem;
            width: 55px;
            height: 55px;
            background: rgba(255,255,255,0.95);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: var(--primary-color);
            transition: var(--transition-smooth);
            z-index: 10001;
            backdrop-filter: blur(10px);
        }

        .lightbox-close:hover {
            background: white;
            transform: scale(1.1) rotate(90deg);
            box-shadow: var(--shadow-lg);
        }

        .lightbox-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 55px;
            height: 55px;
            background: rgba(255,255,255,0.95);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-color);
            transition: var(--transition-smooth);
            z-index: 10001;
            backdrop-filter: blur(10px);
        }

        .lightbox-nav:hover {
            background: white;
            transform: translateY(-50%) scale(1.15);
            box-shadow: var(--shadow-lg);
        }

        .lightbox-nav.prev {
            left: 2rem;
        }

        .lightbox-nav.next {
            right: 2rem;
        }

        /* Enhanced Counter */
        .lightbox-counter {
            position: absolute;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.95rem;
            z-index: 10001;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Lightbox Info Panel */
        .lightbox-info {
            position: absolute;
            top: 2rem;
            left: 2rem;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            max-width: 300px;
            z-index: 10001;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .lightbox-info h4 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
        }

        .lightbox-info p {
            font-size: 0.85rem;
            opacity: 0.9;
            margin: 0;
        }

        /* Thumbnail Strip */
        .lightbox-thumbnails {
            position: absolute;
            bottom: 6rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 0.5rem;
            background: rgba(0, 0, 0, 0.7);
            padding: 1rem;
            border-radius: 15px;
            max-width: 80%;
            overflow-x: auto;
            z-index: 10001;
            backdrop-filter: blur(10px);
        }

        .lightbox-thumbnail {
            width: 60px;
            height: 40px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            opacity: 0.6;
            transition: var(--transition-smooth);
            border: 2px solid transparent;
        }

        .lightbox-thumbnail.active {
            opacity: 1;
            border-color: var(--secondary-color);
            transform: scale(1.1);
        }

        .lightbox-thumbnail:hover {
            opacity: 0.9;
            transform: scale(1.05);
        }

        /* Error State */
        .error-container {
            text-align: center;
            padding: 5rem 2rem;
        }

        .error-icon {
            font-size: 5rem;
            color: var(--secondary-color);
            margin-bottom: 2rem;
        }

        .error-container h4 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .error-container p {
            font-size: 1.1rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .facts-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .property-specs {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 992px) {
            .action-panel {
                position: static;
                margin-top: 3rem;
            }

            .slideshow-container {
                height: 400px;
            }

            .slideshow-nav {
                width: 45px;
                height: 45px;
                font-size: 1.25rem;
            }

            .slideshow-nav.prev {
                left: 1rem;
            }

            .slideshow-nav.next {
                right: 1rem;
            }

            .slideshow-play-pause {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .gallery-info {
                font-size: 0.8rem;
                padding: 0.5rem 1rem;
            }

            .view-gallery-btn {
                font-size: 0.8rem;
                padding: 0.5rem 1rem;
            }

            .content-wrapper {
                padding: 0 1rem 2rem;
            }

            .detail-card {
                padding: 2rem;
            }

            .lightbox-thumbnails {
                bottom: 4rem;
                max-width: 90%;
            }

            .lightbox-thumbnail {
                width: 50px;
                height: 35px;
            }
        }

        @media (max-width: 768px) {
            .property-price {
                font-size: 1.85rem;
            }

            .property-address {
                font-size: 1.05rem;
            }

            .property-header {
                padding: 2rem 0 1.5rem;
            }

            .property-specs {
                gap: 1.5rem;
            }

            .spec-value {
                font-size: 1.35rem;
            }

            .slideshow-container {
                height: 300px;
            }

            .slideshow-nav {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }

            .slideshow-play-pause {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }

            .slideshow-controls {
                bottom: 1.5rem;
            }

            .slideshow-dot {
                width: 10px;
                height: 10px;
            }

            .gallery-info {
                top: 1rem;
                right: 1rem;
                font-size: 0.75rem;
                padding: 0.5rem 0.875rem;
            }

            .view-gallery-btn {
                bottom: 1.5rem;
                right: 1.5rem;
                font-size: 0.75rem;
                padding: 0.5rem 1rem;
            }

            .facts-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .amenities-grid {
                grid-template-columns: 1fr;
            }

            .detail-card {
                padding: 1.75rem;
                margin-bottom: 1.5rem;
            }

            .detail-card h3 {
                font-size: 1.35rem;
            }

            .lightbox-nav, .lightbox-close {
                width: 44px;
                height: 44px;
                font-size: 1.25rem;
            }

            .lightbox-nav.prev {
                left: 1rem;
            }

            .lightbox-nav.next {
                right: 1rem;
            }

            .lightbox-close {
                top: 1rem;
                right: 1rem;
            }

            .lightbox-info {
                top: 1rem;
                left: 1rem;
                max-width: 200px;
                padding: 0.75rem 1rem;
            }

            .lightbox-info h4 {
                font-size: 0.9rem;
            }

            .lightbox-info p {
                font-size: 0.75rem;
            }

            .lightbox-thumbnails {
                bottom: 3rem;
                padding: 0.75rem;
            }

            .lightbox-thumbnail {
                width: 45px;
                height: 30px;
            }

            .agent-card-content {
                padding: 1.75rem;
            }

            .agent-contact-info {
                padding: 1.25rem 1.75rem;
            }
        }

        @media (max-width: 576px) {
            .property-title-section {
                flex-direction: column;
            }

            .property-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .property-specs {
                flex-direction: column;
                gap: 1rem;
                padding: 1.25rem 0 0;
            }
            
            .spec-item {
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--border-color);
            }
            
            .spec-item:last-child {
                border-bottom: none;
                padding-bottom: 0;
            }

            .breadcrumb-custom .container {
                padding: 0 1rem;
            }

            .content-wrapper {
                padding: 0 1rem 2rem;
            }

            .description-text {
                font-size: 0.95rem;
            }

            .detail-card {
                padding: 1.5rem;
            }

            .detail-card h3 {
                font-size: 1.25rem;
                margin-bottom: 1.25rem;
            }

            .agent-name {
                font-size: 1.35rem;
            }

            .action-button {
                min-width: 40px;
                height: 40px;
            }

            #viewsBadge {
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>

<!-- Include your navbar here -->
<?php include 'navbar.php'; ?>

<main class="property-container">
    <?php if ($error_message): ?>
        <div class="content-wrapper">
            <div class="error-container">
                <div class="error-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <h4>Content Not Available</h4>
                <p><?php echo $error_message; ?></p>
                <a href="index.php" class="btn agent-btn agent-btn-primary" style="display: inline-flex; width: auto;">
                    <i class="bi bi-house-door-fill"></i>
                    Return to Home
                </a>
            </div>
        </div>
    <?php elseif ($property_data): ?>
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="breadcrumb-custom">
            <div class="container">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php#properties"><?php echo htmlspecialchars($property_data['City']); ?></a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($property_data['StreetAddress']); ?></li>
                </ol>
            </div>
        </nav>

        <!-- Auto Slideshow Gallery -->
        <div class="gallery-section">
            <div class="slideshow-container" id="slideshow">
                <?php if (!empty($property_images)): ?>
                    <?php foreach ($property_images as $index => $image): ?>
                        <div class="slide <?php echo $index === 0 ? 'active' : ''; ?>">
                            <img src="../<?php echo htmlspecialchars($image); ?>" 
                                 alt="Property image <?php echo $index + 1; ?>"
                                 onclick="openLightbox(<?php echo $index; ?>)">
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="slide active">
                        <img src="<?= BASE_URL ?>images/placeholder.svg" 
                             alt="No image available">
                    </div>
                <?php endif; ?>

                <!-- Navigation Arrows -->
                <button class="slideshow-nav prev" onclick="changeSlide(-1)">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button class="slideshow-nav next" onclick="changeSlide(1)">
                    <i class="bi bi-chevron-right"></i>
                </button>

                <!-- Play/Pause Button -->
                <button class="slideshow-play-pause" id="playPauseBtn" onclick="toggleSlideshow()">
                    <i class="bi bi-pause-fill" id="playPauseIcon"></i>
                </button>

                <!-- Image Info -->
                <div class="gallery-info">
                    <i class="bi bi-images"></i>
                    <span id="imageCounter">1</span> / <?php echo count($property_images) ?: 1; ?>
                </div>

                <!-- View Full Gallery Button -->
                <button class="view-gallery-btn" onclick="openLightbox(0)">
                    <i class="bi bi-arrows-fullscreen"></i>
                    View Full Gallery
                </button>

                <!-- Dots Navigation -->
                <?php if (count($property_images) > 1): ?>
                <div class="slideshow-controls">
                    <?php foreach ($property_images as $index => $image): ?>
                        <span class="slideshow-dot <?php echo $index === 0 ? 'active' : ''; ?>" 
                              onclick="currentSlide(<?php echo $index + 1; ?>)"></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-wrapper">
            <div class="row">
                <!-- Left Column: Details -->
                <div class="col-lg-8">
                    <!-- Property Header -->
                    <div class="property-header">
                        <div class="property-title-section">
                            <div>
                                <div class="property-price">
                                    ₱<?php echo number_format($property_data['ListingPrice']); ?>
                                    <?php if (isset($property_data['Status']) && trim($property_data['Status']) === 'For Rent'): ?>
                                        <span class="text-muted" style="font-size:0.95rem; font-weight:600;">/ month</span>
                                    <?php endif; ?>
                                </div>
                                <div class="property-address">
                                    <?php echo htmlspecialchars($property_data['StreetAddress'] . ', ' . $property_data['City'] . ', ' . $property_data['Province']); ?>
                                </div>
                                <div class="property-meta">
                                    <span><strong><?php echo $property_data['Bedrooms']; ?></strong> bd</span>
                                    <span>|</span>
                                    <span><strong><?php echo $property_data['Bathrooms']; ?></strong> ba</span>
                                    <span>|</span>
                                    <span><strong><?php echo number_format($property_data['SquareFootage']); ?></strong> sqft</span>
                                    <span>|</span>
                                    <span><?php echo htmlspecialchars($property_data['PropertyType']); ?></span>
                                </div>
                                <?php if (isset($property_data['Status']) && trim($property_data['Status']) === 'For Rent'): ?>
                                    <div class="text-muted mt-2" style="font-size:0.95rem;">
                                        <span class="me-3"><i class="bi bi-shield-lock me-1"></i>Deposit: <strong>
                                            <?php echo isset($rental_details['security_deposit']) ? '₱' . number_format((float)$rental_details['security_deposit']) : '—'; ?>
                                        </strong></span>
                                        <span class="me-3"><i class="bi bi-calendar2-week me-1"></i>Lease: <strong>
                                            <?php echo isset($rental_details['lease_term_months']) ? ((int)$rental_details['lease_term_months']) . ' mo' : '—'; ?>
                                        </strong></span>
                                        <span class="me-3"><i class="bi bi-lamp me-1"></i>Furnishing: <strong>
                                            <?php echo isset($rental_details['furnishing']) ? htmlspecialchars($rental_details['furnishing']) : '—'; ?>
                                        </strong></span>
                                        <span class="me-3"><i class="bi bi-calendar-event me-1"></i>Available: <strong>
                                            <?php echo isset($rental_details['available_from']) && !empty($rental_details['available_from']) ? date('M d, Y', strtotime($rental_details['available_from'])) : '—'; ?>
                                        </strong></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="property-actions">
                                <button class="action-button share-button" onclick="shareProperty()" title="Share property">
                                    <i class="bi bi-share-fill"></i>
                                </button>
                                <button class="action-button like-button" 
                                        onclick="likeProperty(this, <?php echo $property_data['property_ID']; ?>)" 
                                        title="Save to favorites">
                                    <i class="bi bi-heart-fill"></i>
                                </button>
                                <!-- Views badge -->
                                <div id="viewsBadge" class="action-button" title="Total views">
                                    <i class="bi bi-eye-fill"></i>
                                    <span id="viewsCountDisplay"><?php echo (int)($property_data['ViewsCount'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Overview -->
                    <div class="detail-card">
                        <h3>
                            <i class="bi bi-text-left"></i>
                            Overview
                        </h3>
                        <p class="description-text">
                            <?php echo nl2br(htmlspecialchars($property_data['ListingDescription'] ?? 'No description provided.')); ?>
                        </p>
                    </div>

                    <!-- Facts and Features -->
                    <div class="detail-card">
                        <h3>
                            <i class="bi bi-house-door"></i>
                            Home Facts
                        </h3>
                        <div class="facts-grid">
                            <div class="fact-item">
                                <div class="fact-label">Property Type</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['PropertyType']); ?></div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">Year Built</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['YearBuilt'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">Square Footage</div>
                                <div class="fact-value"><?php echo number_format($property_data['SquareFootage']); ?> sqft</div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">Lot Size</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['LotSize'] ?? 'N/A'); ?> acres</div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">Bedrooms</div>
                                <div class="fact-value"><?php echo $property_data['Bedrooms']; ?></div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">Bathrooms</div>
                                <div class="fact-value"><?php echo $property_data['Bathrooms']; ?></div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">Barangay</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['Barangay'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">Province</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['Province']); ?></div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">Parking</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['ParkingType'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="fact-item">
                                <div class="fact-label">MLS Number</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['MLSNumber'] ?? 'N/A'); ?></div>
                            </div>
                            <?php if (!empty($property_data['Source'])): ?>
                            <div class="fact-item">
                                <div class="fact-label">Source (MLS)</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['Source']); ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="fact-item">
                                <div class="fact-label">Status</div>
                                <div class="fact-value"><?php echo htmlspecialchars($property_data['Status']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Amenities -->
                    <?php if (!empty($property_amenities)): ?>
                    <div class="detail-card">
                        <h3>
                            <i class="bi bi-check2-circle"></i>
                            Interior Features
                        </h3>
                        <div class="amenities-grid">
                            <?php foreach ($property_amenities as $amenity): ?>
                                <div class="amenity-item">
                                    <?php echo htmlspecialchars($amenity); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column: Agent Card -->
                <div class="col-lg-4">
                    <div class="action-panel">
                        <?php if ($agent_info): ?>
                        <div class="detail-card agent-card">
                            <div class="agent-card-content">
                                <h5>Listed By</h5>
                                <div style="display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.5rem;">
                                    <div class="agent-image-wrapper">
                                        <img src="<?php echo !empty($agent_info['profile_picture_url']) ? '../' . htmlspecialchars($agent_info['profile_picture_url']) : BASE_URL . 'images/placeholder-avatar.svg'; ?>" 
                                             alt="Agent" 
                                             class="agent-contact-img">
                                        <div class="agent-badge">
                                            <i class="bi bi-patch-check-fill"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="agent-name">
                                            <?php echo htmlspecialchars(trim($agent_info['first_name'] . ' ' . $agent_info['last_name'])); ?>
                                        </div>
                                        <div class="agent-specialization">
                                            <?php echo htmlspecialchars($agent_info['specialization'] ?? 'Real Estate Agent'); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="agent-actions">
                                    <?php if ($property_data['Status'] !== 'Sold'): ?>
                                    <button class="btn agent-btn agent-btn-primary" data-bs-toggle="modal" data-bs-target="#requestTourModal">
                                        <i class="bi bi-calendar-check"></i>
                                        Request a Tour
                                    </button>
                                    <button class="btn agent-btn agent-btn-secondary" data-bs-toggle="modal" data-bs-target="#contactInfoModal">
                                        <i class="bi bi-info-circle"></i>
                                        Contact Information
                                    </button>
                                    <?php else: ?>
                                    <div class="sold-notice">
                                        <i class="bi bi-house-check"></i>
                                        This property has been sold
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($property_data['Status'] !== 'Sold'): ?>
                            <div class="agent-contact-info">
                                <span class="agent-contact-label">Quick Contact</span>
                                <a href="tel:<?php echo htmlspecialchars($agent_info['phone_number']); ?>">
                                    <i class="bi bi-telephone-fill"></i>
                                    <?php echo htmlspecialchars($agent_info['phone_number']); ?>
                                </a>
                                <a href="mailto:<?php echo htmlspecialchars($agent_info['email']); ?>">
                                    <i class="bi bi-envelope-fill"></i>
                                    <?php echo htmlspecialchars($agent_info['email']); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- Enhanced Fullscreen Lightbox Gallery -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <!-- Close Button -->
    <button class="lightbox-close" onclick="closeLightbox()">
        <i class="bi bi-x-lg"></i>
    </button>

    <!-- Navigation Arrows -->
    <button class="lightbox-nav prev" onclick="event.stopPropagation(); navigateLightbox(-1)">
        <i class="bi bi-chevron-left"></i>
    </button>
    <button class="lightbox-nav next" onclick="event.stopPropagation(); navigateLightbox(1)">
        <i class="bi bi-chevron-right"></i>
    </button>

    <!-- Image Container -->
    <div class="lightbox-content" onclick="event.stopPropagation()">
        <img src="" alt="Property image" id="lightboxImage" class="lightbox-image">
    </div>

    <!-- Image Info Panel -->
    <div class="lightbox-info" id="lightboxInfo">
        <h4>Property Gallery</h4>
        <p>Click the arrows or use keyboard keys (← →) to navigate</p>
    </div>

    <!-- Counter -->
    <div class="lightbox-counter">
        <span id="lightboxCounter">1</span> / <?php echo count($property_images) ?: 1; ?>
    </div>

    <!-- Thumbnail Strip -->
    <?php if (count($property_images) > 1): ?>
    <div class="lightbox-thumbnails" onclick="event.stopPropagation()">
        <?php foreach ($property_images as $index => $image): ?>
            <img src="../<?php echo htmlspecialchars($image); ?>" 
                 alt="Thumbnail <?php echo $index + 1; ?>"
                 class="lightbox-thumbnail <?php echo $index === 0 ? 'active' : ''; ?>"
                 onclick="jumpToImage(<?php echo $index; ?>)">
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Request Tour Modal -->
<div class="modal fade" id="requestTourModal" tabindex="-1" aria-labelledby="requestTourModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="requestTourModalLabel">Request a Tour</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 pb-4">
                <!-- Inline alert container for server messages -->
                <div id="tourRequestAlert" class="alert d-none" role="alert"></div>

                <form id="tourRequestForm">
                    <input type="hidden" name="property_id" value="<?php echo $property_data['property_ID']; ?>">
                    <p class="text-muted mb-4">Select your preferred date and time for a tour. An agent will contact you to confirm.</p>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="tourDate" class="form-label">Date</label>
                            <input type="date" class="form-control" id="tourDate" name="tour_date" required>
                            <div class="invalid-feedback" id="tourDateFeedback"></div>
                        </div>
                        <div class="col-md-6">
                            <label for="tourTime" class="form-label">Time</label>
                            <select class="form-select" id="tourTime" name="time" required>
                                <option value="">Select a time</option>
                                <option value="09:00:00">9:00 AM</option>
                                <option value="10:00:00">10:00 AM</option>
                                <option value="11:00:00">11:00 AM</option>
                                <option value="13:00:00">1:00 PM</option>
                                <option value="14:00:00">2:00 PM</option>
                                <option value="15:00:00">3:00 PM</option>
                                <option value="16:00:00">4:00 PM</option>
                            </select>
                            <div class="invalid-feedback" id="tourTimeFeedback"></div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tourName" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="tourName" name="name" required>
                        <div class="invalid-feedback" id="tourNameFeedback"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tourEmail" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="tourEmail" name="email" required>
                        <div class="invalid-feedback" id="tourEmailFeedback"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tourPhone" class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="tourPhone" name="phone" required>
                        <div class="invalid-feedback" id="tourPhoneFeedback"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tourMessage" class="form-label">Message (Optional)</label>
                        <textarea class="form-control" id="tourMessage" name="message" rows="3" placeholder="Any specific requests or questions?"></textarea>
                    </div>
                    
                    <button type="submit" class="btn agent-btn agent-btn-primary w-100">
                        <i class="bi bi-calendar-check"></i>
                        Submit Tour Request
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Contact Information Modal -->
<div class="modal fade" id="contactInfoModal" tabindex="-1" aria-labelledby="contactInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border: none; border-radius: 12px; box-shadow: var(--shadow-lg);">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--secondary-color) 0%, var(--accent-gold) 100%); color: white; border: none; border-radius: 12px 12px 0 0; padding: 1.5rem 2rem;">
                <h5 class="modal-title fw-bold" id="contactInfoModalLabel" style="font-size: 1.25rem;">
                    <i class="bi bi-person-lines-fill me-2"></i>
                    Agent Contact Information
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 2rem;">
                <!-- Agent Profile Section -->
                <div style="text-align: center; padding-bottom: 1.5rem; border-bottom: 2px solid var(--border-color); margin-bottom: 2rem;">
                    <div style="position: relative; display: inline-block; margin-bottom: 1rem;">
                        <img src="<?php echo !empty($agent_info['profile_picture_url']) ? '../' . htmlspecialchars($agent_info['profile_picture_url']) : BASE_URL . 'images/placeholder-avatar.svg'; ?>" 
                             alt="Agent" 
                             style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 4px solid var(--secondary-color); box-shadow: var(--shadow-md);">
                        <div style="position: absolute; bottom: 0; right: 0; width: 32px; height: 32px; background: linear-gradient(135deg, var(--secondary-color) 0%, var(--accent-gold) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: var(--shadow-sm);">
                            <i class="bi bi-patch-check-fill" style="color: white; font-size: 0.9rem;"></i>
                        </div>
                    </div>
                    <h4 style="font-size: 1.5rem; font-weight: 800; color: var(--primary-color); margin-bottom: 0.5rem;">
                        <?php echo htmlspecialchars(trim($agent_info['first_name'] . ' ' . $agent_info['last_name'])); ?>
                    </h4>
                    <p style="color: var(--text-secondary); font-size: 0.95rem; margin: 0;">
                        <?php echo htmlspecialchars($agent_info['specialization'] ?? 'Real Estate Agent'); ?>
                    </p>
                </div>

                <!-- Contact Details -->
                <div style="display: grid; gap: 1.25rem;">
                    <!-- Phone -->
                    <div style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: rgba(188, 158, 66, 0.05); border-radius: 10px; border: 1px solid rgba(188, 158, 66, 0.15);">
                        <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--secondary-color) 0%, var(--accent-gold) 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);">
                            <i class="bi bi-telephone-fill" style="color: white; font-size: 1.25rem;"></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); font-weight: 700; margin-bottom: 0.25rem;">Phone Number</div>
                            <a href="tel:<?php echo htmlspecialchars($agent_info['phone_number']); ?>" style="color: var(--primary-color); text-decoration: none; font-size: 1.05rem; font-weight: 600;">
                                <?php echo htmlspecialchars($agent_info['phone_number']); ?>
                            </a>
                        </div>
                        <a href="tel:<?php echo htmlspecialchars($agent_info['phone_number']); ?>" class="btn btn-sm" style="background: white; border: 2px solid var(--secondary-color); color: var(--secondary-color); padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600; transition: var(--transition-smooth);">
                            <i class="bi bi-telephone"></i> Call
                        </a>
                    </div>

                    <!-- Email -->
                    <div style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: rgba(188, 158, 66, 0.05); border-radius: 10px; border: 1px solid rgba(188, 158, 66, 0.15);">
                        <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--secondary-color) 0%, var(--accent-gold) 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);">
                            <i class="bi bi-envelope-fill" style="color: white; font-size: 1.25rem;"></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); font-weight: 700; margin-bottom: 0.25rem;">Email Address</div>
                            <a href="mailto:<?php echo htmlspecialchars($agent_info['email']); ?>" style="color: var(--primary-color); text-decoration: none; font-size: 1.05rem; font-weight: 600; word-break: break-all;">
                                <?php echo htmlspecialchars($agent_info['email']); ?>
                            </a>
                        </div>
                        <a href="mailto:<?php echo htmlspecialchars($agent_info['email']); ?>" class="btn btn-sm" style="background: white; border: 2px solid var(--secondary-color); color: var(--secondary-color); padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600; transition: var(--transition-smooth);">
                            <i class="bi bi-envelope"></i> Email
                        </a>
                    </div>
                </div>

                <!-- Additional Info -->
                <div style="margin-top: 2rem; padding: 1.25rem; background: #f9f9f9; border-radius: 10px; border-left: 4px solid var(--secondary-color);">
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                        <i class="bi bi-info-circle-fill" style="color: var(--secondary-color); font-size: 1.25rem;"></i>
                        <strong style="color: var(--primary-color);">Contact Guidelines</strong>
                    </div>
                    <p style="margin: 0; font-size: 0.9rem; color: var(--text-secondary); line-height: 1.6;">
                        Feel free to reach out via phone or email. The agent typically responds within 24 hours during business days. For urgent inquiries, calling is recommended.
                    </p>
                </div>
            </div>
            <div class="modal-footer" style="border: none; padding: 1rem 2rem 2rem; background: transparent;">
                <button type="button" class="btn agent-btn agent-btn-primary w-100" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#requestTourModal">
                    <i class="bi bi-calendar-check"></i>
                    Request a Tour Instead
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
<script>
    // Property images array and slideshow variables
    const propertyImages = <?php echo json_encode(array_map(function($img) { return '../' . $img; }, $property_images)); ?>;
    let currentSlideIndex = 0;
    let currentLightboxIndex = 0;
    let slideshowInterval;
    let isPlaying = true;

    // Auto Slideshow Functions
    function initSlideshow() {
        if (propertyImages.length <= 1) return;
        
        startSlideshow();
    }

    function startSlideshow() {
        slideshowInterval = setInterval(() => {
            if (isPlaying) {
                changeSlide(1);
            }
        }, 4000); // Change slide every 4 seconds
    }

    function stopSlideshow() {
        clearInterval(slideshowInterval);
    }

    function toggleSlideshow() {
        const playPauseIcon = document.getElementById('playPauseIcon');
        
        if (isPlaying) {
            stopSlideshow();
            isPlaying = false;
            playPauseIcon.className = 'bi bi-play-fill';
        } else {
            startSlideshow();
            isPlaying = true;
            playPauseIcon.className = 'bi bi-pause-fill';
        }
    }

    function changeSlide(direction) {
        const slides = document.querySelectorAll('.slide');
        const dots = document.querySelectorAll('.slideshow-dot');
        
        if (slides.length <= 1) return;

        // Remove active class from current slide and dot
        slides[currentSlideIndex].classList.remove('active');
        if (dots[currentSlideIndex]) {
            dots[currentSlideIndex].classList.remove('active');
        }

        // Calculate new slide index
        currentSlideIndex += direction;
        
        if (currentSlideIndex < 0) {
            currentSlideIndex = slides.length - 1;
        } else if (currentSlideIndex >= slides.length) {
            currentSlideIndex = 0;
        }

        // Add active class to new slide and dot
        slides[currentSlideIndex].classList.add('active');
        if (dots[currentSlideIndex]) {
            dots[currentSlideIndex].classList.add('active');
        }

        // Update counter
        const counter = document.getElementById('imageCounter');
        if (counter) {
            counter.textContent = currentSlideIndex + 1;
        }
    }

    function currentSlide(index) {
        const slides = document.querySelectorAll('.slide');
        const dots = document.querySelectorAll('.slideshow-dot');
        
        if (index < 1 || index > slides.length) return;

        // Remove active classes
        slides[currentSlideIndex].classList.remove('active');
        if (dots[currentSlideIndex]) {
            dots[currentSlideIndex].classList.remove('active');
        }

        // Set new index (convert from 1-based to 0-based)
        currentSlideIndex = index - 1;

        // Add active classes
        slides[currentSlideIndex].classList.add('active');
        if (dots[currentSlideIndex]) {
            dots[currentSlideIndex].classList.add('active');
        }

        // Update counter
        const counter = document.getElementById('imageCounter');
        if (counter) {
            counter.textContent = currentSlideIndex + 1;
        }
    }

    // Enhanced Lightbox Gallery Functions
    function openLightbox(index) {
        if (propertyImages.length === 0) return;
        
        currentLightboxIndex = index;
        const lightbox = document.getElementById('lightbox');
        const lightboxImage = document.getElementById('lightboxImage');
        const lightboxCounter = document.getElementById('lightboxCounter');
        const thumbnails = document.querySelectorAll('.lightbox-thumbnail');
        
        // Set image and counter
        lightboxImage.src = propertyImages[currentLightboxIndex];
        lightboxCounter.textContent = (currentLightboxIndex + 1) + ' / ' + propertyImages.length;
        
        // Update thumbnail active state
        thumbnails.forEach((thumb, i) => {
            thumb.classList.toggle('active', i === currentLightboxIndex);
        });
        
        // Show lightbox
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Pause slideshow when lightbox opens
        if (isPlaying) {
            stopSlideshow();
        }
    }

    function closeLightbox() {
        const lightbox = document.getElementById('lightbox');
        lightbox.classList.remove('active');
        document.body.style.overflow = 'auto';
        
        // Resume slideshow when lightbox closes
        if (isPlaying) {
            startSlideshow();
        }
    }

    function navigateLightbox(direction) {
        if (propertyImages.length <= 1) return;
        
        currentLightboxIndex += direction;
        
        if (currentLightboxIndex < 0) {
            currentLightboxIndex = propertyImages.length - 1;
        } else if (currentLightboxIndex >= propertyImages.length) {
            currentLightboxIndex = 0;
        }
        
        updateLightboxImage();
    }

    function jumpToImage(index) {
        currentLightboxIndex = index;
        updateLightboxImage();
    }

    function updateLightboxImage() {
        const lightboxImage = document.getElementById('lightboxImage');
        const lightboxCounter = document.getElementById('lightboxCounter');
        const thumbnails = document.querySelectorAll('.lightbox-thumbnail');
        
        // Fade out effect
        lightboxImage.style.opacity = '0';
        
        setTimeout(() => {
            lightboxImage.src = propertyImages[currentLightboxIndex];
            lightboxCounter.textContent = (currentLightboxIndex + 1) + ' / ' + propertyImages.length;
            
            // Update thumbnail active state
            thumbnails.forEach((thumb, i) => {
                thumb.classList.toggle('active', i === currentLightboxIndex);
            });
            
            // Fade in effect
            lightboxImage.style.opacity = '1';
        }, 200);
    }

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        const lightbox = document.getElementById('lightbox');
        
        if (lightbox.classList.contains('active')) {
            switch(e.key) {
                case 'ArrowLeft':
                    e.preventDefault();
                    navigateLightbox(-1);
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    navigateLightbox(1);
                    break;
                case 'Escape':
                    e.preventDefault();
                    closeLightbox();
                    break;
                case ' ':
                    e.preventDefault();
                    break;
            }
        } else {
            // Slideshow keyboard controls when not in lightbox
            switch(e.key) {
                case 'ArrowLeft':
                    changeSlide(-1);
                    break;
                case 'ArrowRight':
                    changeSlide(1);
                    break;
                case ' ':
                    e.preventDefault();
                    toggleSlideshow();
                    break;
            }
        }
    });

    // Initialize slideshow when page loads
    document.addEventListener('DOMContentLoaded', function() {
        initSlideshow();
    });

    // Like functionality
    function likeProperty(buttonElement, propertyId) {
        if (buttonElement.classList.contains('liked')) return; 

        buttonElement.classList.add('liked');
        
        // Add animation
        buttonElement.style.transform = 'scale(1.2)';
        setTimeout(() => {
            buttonElement.style.transform = 'scale(1)';
        }, 200);

        fetch('like_property.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'property_id=' + propertyId
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                buttonElement.classList.remove('liked');
            }
        })
        .catch(error => {
            buttonElement.classList.remove('liked');
            console.error('Error:', error);
        });
    }

    // Share functionality
    function shareProperty() {
        if (navigator.share) {
            navigator.share({
                title: document.title,
                text: 'Check out this amazing property!',
                url: window.location.href
            }).catch(err => console.log('Error sharing:', err));
        } else {
            // Fallback: copy to clipboard
            navigator.clipboard.writeText(window.location.href).then(() => {
                alert('Link copied to clipboard!');
            }).catch(err => {
                console.error('Could not copy text: ', err);
            });
        }
    }

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
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
        
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
                submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>Request Sent!';
                submitBtn.classList.remove('agent-btn-primary');
                submitBtn.classList.add('btn-success');

                // Reset form after brief confirmation
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    submitBtn.classList.remove('btn-success');
                    submitBtn.classList.add('agent-btn-primary');
                    // Keep success alert visible; clear form fields
                    (document.getElementById('tourRequestForm') || this).reset();
                    // Clear validation states after reset
                    Object.values(fieldMap).forEach(({ input, feedback }) => {
                        const el = document.querySelector(input);
                        const fb = document.querySelector(feedback);
                        if (el) el.classList.remove('is-invalid');
                        if (fb) fb.textContent = '';
                    });
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

    // Views count: ping server once per session per property to increment safely
    (function(){
        const propertyId = <?php echo (int)$property_id; ?>;
        const storageKey = 'viewed_property_' + propertyId;
        const viewsDisplay = document.getElementById('viewsCountDisplay');

        // Only ping if not already recorded in this browser session
        if (!sessionStorage.getItem(storageKey)) {
            fetch('increment_property_view.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'property_id=' + propertyId
            }).then(async r => {
                const text = await r.text();
                if (!r.ok) {
                    console.error('Increment view HTTP error', r.status, text);
                    return;
                }
                let data;
                try { data = JSON.parse(text); } catch(e) { console.error('Invalid JSON from increment endpoint', text); return; }
                if (data && data.success && typeof data.views !== 'undefined') {
                    if (viewsDisplay) viewsDisplay.textContent = data.views;
                    sessionStorage.setItem(storageKey, '1');
                }
            }).catch(err => console.error('Failed to increment property view:', err));
        }
    })();
</script>
</body>
</html>
