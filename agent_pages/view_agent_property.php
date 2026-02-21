<?php
$active_page = 'agent_property.php';
session_start();
include '../connection.php';

// Security Check: Ensure agent is logged in
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}

$agent_account_id = $_SESSION['account_id'];

// Fetch agent info for navbar (including profile picture if column exists)
$sql_agent_info = "SELECT first_name, last_name, username FROM accounts WHERE account_id = ?";
$stmt_agent_info = $conn->prepare($sql_agent_info);
$stmt_agent_info->bind_param("i", $agent_account_id);
$stmt_agent_info->execute();
$result_agent_info = $stmt_agent_info->get_result();
$agent_info = $result_agent_info->fetch_assoc();
$stmt_agent_info->close();
$agent_username = $agent_info['username'] ?? 'Agent';

// Check if profile_picture_url column exists and fetch it separately
$check_column = $conn->query("SHOW COLUMNS FROM accounts LIKE 'profile_picture_url'");
if ($check_column && $check_column->num_rows > 0) {
    $sql_profile = "SELECT profile_picture_url FROM accounts WHERE account_id = ?";
    $stmt_profile = $conn->prepare($sql_profile);
    $stmt_profile->bind_param("i", $agent_account_id);
    $stmt_profile->execute();
    $result_profile = $stmt_profile->get_result();
    $profile_data = $result_profile->fetch_assoc();
    $stmt_profile->close();
    if ($profile_data) {
        $agent_info['profile_picture_url'] = $profile_data['profile_picture_url'];
    }
}

$property_data = null;
$property_images = [];
$amenities = [];
$price_history = [];
$error_message = '';
$success_message = '';

// Get property ID from URL
$property_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($property_id <= 0) {
    header("Location: agent_property.php");
    exit();
}

// Flash messages handling
if (isset($_GET['status']) && isset($_GET['msg'])) {
    if ($_GET['status'] === 'success') $success_message = htmlspecialchars(urldecode($_GET['msg']));
    if ($_GET['status'] === 'error') $error_message = htmlspecialchars(urldecode($_GET['msg']));
}

// Fetch Property Data (Main Query)
$sql_property = "SELECT * FROM property WHERE property_ID = ?";
$stmt_property = $conn->prepare($sql_property);
$stmt_property->bind_param("i", $property_id);
$stmt_property->execute();
$result_property = $stmt_property->get_result();
$property_data = $result_property->fetch_assoc();
$stmt_property->close();

// Check if property exists and belongs to this agent
if (!$property_data) {
    header("Location: agent_property.php");
    exit();
}

// Verify if this property belongs to the current agent
$sql_check_owner = "SELECT 1 FROM property_log 
                    WHERE property_id = ? AND account_id = ? 
                    LIMIT 1";
$stmt_check = $conn->prepare($sql_check_owner);
$stmt_check->bind_param("ii", $property_id, $agent_account_id);
$stmt_check->execute();
$is_owner = $stmt_check->get_result()->num_rows > 0;
$stmt_check->close();

// For debugging
error_log("Agent ID: " . $agent_account_id . " checking property: " . $property_id . " - is owner: " . ($is_owner ? "Yes" : "No"));

// Commenting out the redirection for now to ensure access
// if (!$is_owner) {
//     header("Location: agent_property.php");
//     exit();
// }

// Fetch price history
$sql_history = "SELECT event_date, event_type, price FROM price_history 
                WHERE property_id = ? ORDER BY event_date DESC";
$stmt_history = $conn->prepare($sql_history);
$stmt_history->bind_param("i", $property_id);
$stmt_history->execute();
$history_result = $stmt_history->get_result();
$price_history_raw = $history_result->fetch_all(MYSQLI_ASSOC);
$stmt_history->close();

// Process Price History for Table Display
$price_history_for_table = array_reverse($price_history_raw);
$price_history = []; // This will be for the formatted table view

// Calculate percentage change
for ($i = 0; $i < count($price_history_raw); $i++) {
    $current_event = $price_history_raw[$i];
    $previous_price = isset($price_history_raw[$i + 1]) ? $price_history_raw[$i + 1]['price'] : null;
    $change_percentage = null;
    $change_class = '';

    if ($previous_price && $previous_price > 0) {
        $change = (($current_event['price'] - $previous_price) / $previous_price) * 100;
        $change_percentage = round($change, 2);
        if ($change > 0) {
            $change_class = 'text-success'; // Price increased
        } elseif ($change < 0) {
            $change_class = 'text-danger'; // Price decreased
        }
    }
    // Add calculated data to a new array
    $price_history[] = [
        'event_date' => date('M d, Y', strtotime($current_event['event_date'])),
        'event_type' => $current_event['event_type'],
        'price' => '₱' . number_format($current_event['price']),
        'change_percentage' => $change_percentage,
        'change_class' => $change_class
    ];
}

// Fetch property images
$sql_images = "SELECT PhotoURL FROM property_images WHERE property_ID = ? ORDER BY SortOrder ASC";
$stmt_images = $conn->prepare($sql_images);
$stmt_images->bind_param("i", $property_id);
$stmt_images->execute();
$result_images = $stmt_images->get_result();
while ($row = $result_images->fetch_assoc()) {
    $property_images[] = $row['PhotoURL'];
}
$stmt_images->close();

// Fetch property amenities
$sql_amenities = "SELECT am.amenity_name FROM property_amenities pa
                  JOIN amenities am ON pa.amenity_id = am.amenity_id
                  WHERE pa.property_id = ?";
$stmt_amenities = $conn->prepare($sql_amenities);
$stmt_amenities->bind_param("i", $property_id);
$stmt_amenities->execute();
$result_amenities = $stmt_amenities->get_result();
while ($row = $result_amenities->fetch_assoc()) {
    $amenities[] = $row['amenity_name'];
}
$stmt_amenities->close();

// Check for sale verification submissions
$sql_sale_verification = "SELECT status FROM sale_verifications 
                         WHERE property_id = ? 
                         ORDER BY submitted_at DESC LIMIT 1";
$stmt_sale = $conn->prepare($sql_sale_verification);
$stmt_sale->bind_param("i", $property_id);
$stmt_sale->execute();
$result_sale = $stmt_sale->get_result();
$sale_verification = $result_sale->fetch_assoc();
$stmt_sale->close();

$sale_status = $sale_verification ? $sale_verification['status'] : null;

// Check if property is sold (locked for editing)
$is_property_sold = ($property_data['Status'] === 'Sold');

// Calculate real-time metrics
$price_per_sqft = 'N/A';
if (!empty($property_data['SquareFootage']) && $property_data['SquareFootage'] > 0) {
    $raw_value = $property_data['ListingPrice'] / $property_data['SquareFootage'];
    $price_per_sqft = '₱' . number_format($raw_value, 2);
}

$listingDateObj = new DateTime($property_data['ListingDate']);
$today = new DateTime();
$interval = $today->diff($listingDateObj);
$days_on_market = $interval->days;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Details - <?php echo htmlspecialchars($property_data['StreetAddress']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Lightbox for gallery -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css">
    <!-- Leaflet for map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --primary-gold: #bc9e42;
            --primary-dark: #161209;
            --secondary-gray: #6c757d;
            --light-bg: #f8f9fa;
            --border-light: #e0e6ed;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--light-bg);
            color: var(--primary-dark);
            line-height: 1.6;
        }

        /* Enhanced Gallery Section */
        .gallery-wrapper {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }
        
        .gallery-container {
            position: relative;
        }
        
        .main-image-container {
            height: 500px;
            overflow: hidden;
            position: relative;
            background: #000;
        }
        
        .main-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .main-image:hover {
            transform: scale(1.05);
        }
        
        .thumbnail-gallery {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            padding: 15px;
            background: #fff;
        }
        
        .thumbnail-item {
            height: 100px;
            overflow: hidden;
            border-radius: 12px;
            cursor: pointer;
            position: relative;
            border: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .thumbnail-item:hover {
            border-color: var(--primary-gold);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .view-all-photos {
            position: absolute;
            bottom: 25px;
            right: 25px;
            background-color: rgba(255,255,255,0.95);
            border: none;
            padding: 12px 24px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.95rem;
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
            color: var(--primary-dark);
        }
        
        .view-all-photos:hover {
            background-color: var(--primary-gold);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Breadcrumb Enhancement */
        .breadcrumb {
            background: transparent;
            padding: 0;
            margin-bottom: 1.5rem;
        }
        
        .breadcrumb-item a {
            color: var(--secondary-gray);
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .breadcrumb-item a:hover {
            color: var(--primary-gold);
        }
        
        .breadcrumb-item.active {
            color: var(--primary-dark);
            font-weight: 500;
        }

        /* Property Header Enhancement */
        .property-header-section {
            background: #fff;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
        }
        
        .property-title {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.75rem;
            line-height: 1.2;
        }
        
        .property-address {
            color: var(--secondary-gray);
            font-size: 1.1rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .property-address i {
            color: var(--primary-gold);
        }
        
        .property-price {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary-gold);
            margin-bottom: 0.5rem;
            letter-spacing: -1px;
        }
        
        .price-label {
            font-size: 0.9rem;
            color: var(--secondary-gray);
            font-weight: 400;
        }

        /* Property Stats Enhancement */
        .property-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 2.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid var(--border-light);
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.2));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-gold);
            font-size: 1.25rem;
        }
        
        .stat-details {
            text-align: left;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: var(--secondary-gray);
            font-weight: 500;
        }

        /* Section Headers */
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--primary-dark);
            position: relative;
            padding-left: 1rem;
        }
        
        .section-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 70%;
            background: var(--primary-gold);
            border-radius: 2px;
        }

        /* Info Cards Enhancement */
        .info-card {
            border-radius: 16px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            padding: 2rem;
            background-color: #fff;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }
        
        .info-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        /* Amenities Grid */
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .amenity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.05), rgba(188, 158, 66, 0.1));
            border-radius: 10px;
            border: 1px solid rgba(188, 158, 66, 0.2);
            transition: all 0.3s ease;
        }
        
        .amenity-item:hover {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.15));
            transform: translateX(5px);
        }
        
        .amenity-item i {
            color: var(--primary-gold);
            font-size: 1.1rem;
        }
        
        .amenity-item span {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--primary-dark);
        }

        /* Metrics Cards */
        .metric-card {
            background: linear-gradient(135deg, #fff, #f8f9fa);
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            border-color: var(--primary-gold);
            box-shadow: var(--shadow-sm);
        }
        
        .metric-label {
            font-size: 0.85rem;
            color: var(--secondary-gray);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .metric-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        /* Action Buttons */
        .action-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            background: linear-gradient(135deg, var(--primary-gold), #a38736);
            color: #fff;
            border: none;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            background: linear-gradient(135deg, #a38736, var(--primary-gold));
        }

        /* Status Badges */
        .status-badge-enhanced {
            padding: 0.6rem 1.25rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-sm);
        }

        /* Map Container */
        #propertyMap {
            height: 350px;
            width: 100%;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
        }

        /* Table Enhancement */
        .table {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table thead th {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.15));
            color: var(--primary-dark);
            font-weight: 600;
            border: none;
            padding: 1rem;
        }
        
        .table tbody tr {
            transition: background 0.3s ease;
        }
        
        .table tbody tr:hover {
            background: rgba(188, 158, 66, 0.05);
        }
        
        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }

        /* Alert Enhancement */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
        }

        /* Description Text */
        .description-text {
            font-size: 1.05rem;
            line-height: 1.8;
            color: #4a5568;
        }

        /* Feature List */
        .feature-list {
            list-style: none;
            padding: 0;
        }
        
        .feature-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .feature-list li:last-child {
            border-bottom: none;
        }
        
        .feature-label {
            font-weight: 600;
            color: var(--primary-dark);
        }
        
        .feature-value {
            color: var(--secondary-gray);
        }

        /* === ENHANCED EDIT MODAL STYLES === */
        
        /* Modal Core */
        .edit-modal-enhanced {
            border: none;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .edit-modal-header {
            background: linear-gradient(135deg, #161209 0%, #2a1f0f 50%, #161209 100%);
            padding: 2rem;
            border-bottom: 3px solid var(--primary-gold);
            position: relative;
            overflow: hidden;
        }
        
        .edit-modal-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 100%;
            background: radial-gradient(circle at top right, rgba(188, 158, 66, 0.15), transparent 70%);
            pointer-events: none;
        }
        
        .modal-icon-wrapper {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-gold), #d4b661);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: var(--primary-dark);
            box-shadow: 0 8px 16px rgba(188, 158, 66, 0.3);
            position: relative;
            z-index: 1;
        }
        
        .modal-title {
            color: #fff;
            font-size: 1.5rem;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }
        
        .modal-subtitle {
            color: rgba(255,255,255,0.7);
            font-size: 0.875rem;
            font-weight: 400;
        }
        
        .edit-modal-body {
            background: linear-gradient(135deg, #fafafa 0%, #f5f5f5 100%);
            padding: 2.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .edit-modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .edit-modal-body::-webkit-scrollbar-track {
            background: #e0e0e0;
            border-radius: 10px;
        }
        
        .edit-modal-body::-webkit-scrollbar-thumb {
            background: var(--primary-gold);
            border-radius: 10px;
        }
        
        /* Progress Indicator */
        .edit-progress-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            position: relative;
        }
        
        .step-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            position: relative;
            z-index: 2;
        }
        
        .progress-step.active .step-circle {
            background: linear-gradient(135deg, var(--primary-gold), #d4b661);
            color: var(--primary-dark);
            box-shadow: 0 4px 16px rgba(188, 158, 66, 0.4);
            transform: scale(1.1);
        }
        
        .step-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #999;
            transition: color 0.3s;
        }
        
        .progress-step.active .step-label {
            color: var(--primary-gold);
        }
        
        .progress-line {
            flex: 1;
            height: 3px;
            background: #e0e0e0;
            margin: 0 0.5rem;
            align-self: flex-start;
            margin-top: 23px;
            position: relative;
            z-index: 1;
        }
        
        /* Edit Cards */
        .edit-card {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid rgba(188, 158, 66, 0.1);
        }
        
        .edit-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        
        .edit-card-header {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.08), rgba(188, 158, 66, 0.15));
            padding: 1.25rem 1.5rem;
            border-bottom: 2px solid rgba(188, 158, 66, 0.2);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .card-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-gold), #d4b661);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-dark);
            font-size: 1.1rem;
            box-shadow: 0 4px 8px rgba(188, 158, 66, 0.25);
        }
        
        .card-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-dark);
        }
        
        .edit-card-body {
            padding: 1.75rem 1.5rem;
        }
        
        /* Enhanced Form Elements */
        .form-group-enhanced {
            position: relative;
        }
        
        .form-label-enhanced {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .form-label-enhanced i {
            color: var(--primary-gold);
            font-size: 0.9rem;
        }
        
        .form-control-enhanced,
        .form-select-enhanced,
        .textarea-enhanced {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: #fff;
            color: var(--primary-dark);
        }
        
        .form-control-enhanced:focus,
        .form-select-enhanced:focus,
        .textarea-enhanced:focus {
            outline: none;
            border-color: var(--primary-gold);
            box-shadow: 0 0 0 4px rgba(188, 158, 66, 0.1);
            background: #fffef8;
        }
        
        .form-control-enhanced:hover,
        .form-select-enhanced:hover,
        .textarea-enhanced:hover {
            border-color: rgba(188, 158, 66, 0.4);
        }
        
        .textarea-enhanced {
            resize: vertical;
            min-height: 120px;
            font-family: inherit;
            line-height: 1.6;
        }
        
        .input-icon-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-gold);
            font-weight: 700;
            font-size: 1.1rem;
            pointer-events: none;
        }
        
        .char-counter {
            text-align: right;
            font-size: 0.75rem;
            color: #999;
            margin-top: 0.5rem;
        }
        
        .info-box {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.08), rgba(188, 158, 66, 0.12));
            border-left: 4px solid var(--primary-gold);
            padding: 1rem 1.25rem;
            border-radius: 8px;
            font-size: 0.875rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
        }
        
        .info-box i {
            color: var(--primary-gold);
            font-size: 1.1rem;
        }
        
        /* Upload Zone */
        .upload-zone {
            position: relative;
        }
        
        .upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            padding: 2.5rem;
            border: 3px dashed rgba(188, 158, 66, 0.3);
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.03), rgba(188, 158, 66, 0.08));
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-label:hover {
            border-color: var(--primary-gold);
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.08), rgba(188, 158, 66, 0.15));
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(188, 158, 66, 0.15);
        }
        
        .upload-icon {
            font-size: 3rem;
            color: var(--primary-gold);
            opacity: 0.8;
        }
        
        .upload-text {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .upload-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-dark);
        }
        
        .upload-subtitle {
            font-size: 0.875rem;
            color: #666;
        }
        
        .upload-input {
            display: none;
        }
        
        /* Photos Grid */
        .photos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1rem;
        }
        
        .photo-item {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .photo-wrapper {
            position: relative;
            width: 100%;
            padding-bottom: 100%;
            background: #f0f0f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .photo-item:hover .photo-wrapper {
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            transform: translateY(-4px);
        }
        
        .photo-img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .photo-item:hover .photo-img {
            transform: scale(1.05);
        }
        
        .cover-badge {
            position: absolute;
            top: 0.75rem;
            left: 0.75rem;
            background: linear-gradient(135deg, var(--primary-gold), #d4b661);
            color: var(--primary-dark);
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            z-index: 3;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .photo-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(180deg, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.7) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding: 0.75rem;
        }
        
        .photo-item:hover .photo-overlay {
            opacity: 1;
        }
        
        .photo-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .photo-btn {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 8px;
            background: rgba(255,255,255,0.95);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .photo-btn:hover {
            background: var(--primary-gold);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.4);
        }
        
        .photo-btn.btn-delete:hover {
            background: #dc3545;
        }
        
        .photo-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        
        /* Enhanced Alerts */
        .alert-enhanced {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideDown 0.4s ease;
        }
        
        .alert-enhanced.alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        
        .alert-enhanced.alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Enhanced Buttons */
        .btn-enhanced {
            padding: 0.875rem 2rem;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .btn-enhanced::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn-enhanced:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-save {
            background: linear-gradient(135deg, var(--primary-gold), #d4b661);
            color: var(--primary-dark);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(188, 158, 66, 0.4);
        }
        
        .btn-cancel {
            background: #6c757d;
            color: #fff;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.2);
        }
        
        .btn-cancel:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 117, 125, 0.3);
        }
        
        .btn-secondary-enhanced {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: #fff;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.2);
        }
        
        .btn-secondary-enhanced:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 117, 125, 0.3);
        }
        
        .modal-footer-enhanced {
            background: linear-gradient(135deg, #fafafa, #f0f0f0);
            padding: 1.5rem 2rem;
            border-top: 2px solid rgba(188, 158, 66, 0.1);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        
        /* Quick Action Buttons */
        .quick-action-btn {
            border-radius: 12px;
            padding: 0.875rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover {
            transform: translateX(5px);
        }
        
        /* Price Timeline */
        .price-timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 0.5rem;
            bottom: -1rem;
            width: 2px;
            background: linear-gradient(180deg, var(--primary-gold), transparent);
        }
        
        .timeline-item:last-child::before {
            display: none;
        }
        
        .timeline-marker {
            position: absolute;
            left: -1.75rem;
            top: 0.25rem;
            width: 0.75rem;
            height: 0.75rem;
            color: var(--primary-gold);
            z-index: 2;
        }
        
        .timeline-content {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.05), rgba(188, 158, 66, 0.1));
            padding: 1rem 1.25rem;
            border-radius: 12px;
            border-left: 3px solid var(--primary-gold);
            transition: all 0.3s ease;
        }
        
        .timeline-content:hover {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.15));
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.2);
        }
        
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .timeline-date {
            font-size: 0.875rem;
            color: var(--secondary-gray);
            font-weight: 500;
        }
        
        .timeline-event {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            background: var(--primary-gold);
            color: var(--primary-dark);
            border-radius: 12px;
            font-weight: 600;
        }
        
        .timeline-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .price-change {
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .price-change.text-success {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .price-change.text-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        /* Current Price Display */
        .current-price-display {
            text-align: center;
        }
        
        .current-price-box {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.2));
            padding: 1.5rem;
            border-radius: 16px;
            border: 2px solid var(--primary-gold);
        }
        
        .current-price-box .currency {
            font-size: 1.5rem;
            color: var(--primary-gold);
            font-weight: 700;
        }
        
        .current-price-box .amount {
            font-size: 2.5rem;
            color: var(--primary-dark);
            font-weight: 800;
            margin-left: 0.5rem;
        }
        
        /* Price Change Preview */
        .price-change-preview {
            animation: slideDown 0.3s ease;
        }
        
        .preview-box {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            padding: 1rem 1.5rem;
            border-radius: 12px;
            border-left: 4px solid #28a745;
            text-align: center;
        }
        
        .preview-box.decrease {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-left-color: #dc3545;
        }
        
        .preview-label {
            font-size: 0.875rem;
            color: var(--secondary-gray);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .preview-value {
            font-size: 1.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .preview-value i {
            font-size: 1.25rem;
        }
        
        /* Tour Request Cards */
        .tour-request-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }
        
        .tour-request-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .requester-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .requester-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-gold), #d4b661);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .requester-details h6 {
            margin: 0;
            font-weight: 700;
            color: var(--primary-dark);
        }
        
        .requester-details p {
            margin: 0;
            font-size: 0.875rem;
            color: var(--secondary-gray);
        }
        
        .tour-status-badge {
            padding: 0.4rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .property-title {
                font-size: 1.75rem;
            }
            
            .property-price {
                font-size: 2rem;
            }
            
            .property-stats {
                gap: 1.5rem;
            }
            
            .edit-progress-bar {
                overflow-x: auto;
                padding-bottom: 1rem;
            }
            
            .photos-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
        }
        
        @media (max-width: 767px) {
            .main-image-container {
                height: 300px;
            }
            
            .thumbnail-gallery {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .property-stats {
                gap: 1rem;
            }
            
            .stat-item {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
            
            .stat-details {
                text-align: center;
            }
            
            .amenities-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }
            
            .edit-modal-body {
                padding: 1.5rem;
            }
            
            .upload-label {
                flex-direction: column;
                padding: 2rem 1rem;
            }
            
            .photos-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <?php include 'agent_navbar.php'; ?>

    <div class="container-fluid py-4" style="max-width: 1400px;">
        <div class="container-fluid px-4">
            <!-- Flash messages -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Breadcrumb navigation -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="agent_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="agent_property.php">My Properties</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Property Details</li>
                </ol>
            </nav>

            <!-- Property header with status badge -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <?php if ($property_data['approval_status'] === 'approved'): ?>
                        <span class="status-badge-enhanced bg-success-subtle text-success-emphasis">
                            <i class="fas fa-check-circle"></i> Live Listing
                        </span>
                    <?php elseif ($property_data['approval_status'] === 'pending'): ?>
                        <span class="status-badge-enhanced bg-warning-subtle text-warning-emphasis">
                            <i class="fas fa-clock"></i> Pending Review
                        </span>
                    <?php else: ?>
                        <span class="status-badge-enhanced bg-danger-subtle text-danger-emphasis">
                            <i class="fas fa-times-circle"></i> Rejected
                        </span>
                    <?php endif; ?>

                    <?php if ($sale_status === 'Pending'): ?>
                        <span class="status-badge-enhanced bg-info-subtle text-info-emphasis">
                            <i class="fas fa-hourglass-half"></i> Sale Verification Pending
                        </span>
                    <?php elseif ($sale_status === 'Approved'): ?>
                        <span class="status-badge-enhanced bg-success-subtle text-success-emphasis">
                            <i class="fas fa-check-circle"></i> Sale Verified
                        </span>
                    <?php elseif ($sale_status === 'Rejected'): ?>
                        <span class="status-badge-enhanced bg-danger-subtle text-danger-emphasis">
                            <i class="fas fa-times-circle"></i> Sale Verification Rejected
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="d-flex gap-2">
                    <?php if ($property_data['approval_status'] === 'approved' && !$sale_status): ?>
                        <button type="button" class="btn btn-success action-btn" data-bs-toggle="modal" data-bs-target="#markSoldModal" 
                                data-property-id="<?php echo $property_id; ?>" 
                                data-property-title="<?php echo htmlspecialchars($property_data['StreetAddress']); ?>">
                            <i class="fas fa-check-circle me-2"></i> Mark as Sold
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row">
                <!-- Left column (gallery and main details) -->
                <div class="col-lg-8 mb-4">
                    <!-- Image gallery -->
                    <div class="gallery-wrapper">
                        <div class="gallery-container">
                            <div class="main-image-container">
                                <?php if (!empty($property_images)): ?>
                                    <a href="../<?php echo htmlspecialchars($property_images[0]); ?>" data-lightbox="property-gallery" data-title="Property Image 1">
                                        <img src="../<?php echo htmlspecialchars($property_images[0]); ?>" class="main-image" alt="Main Property Image">
                                    </a>
                                    <?php if (count($property_images) > 1): ?>
                                        <button class="btn view-all-photos" data-bs-toggle="modal" data-bs-target="#galleryModal">
                                            <i class="fas fa-images me-2"></i> View All <?php echo count($property_images); ?> Photos
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <img src="https://via.placeholder.com/800x600?text=No+Image+Available" class="main-image" alt="No Image Available">
                                <?php endif; ?>
                            </div>
                            
                            <?php if (count($property_images) > 1): ?>
                                <div class="thumbnail-gallery">
                                    <?php 
                                    $max_thumbs = min(count($property_images), 5); 
                                    for($i = 1; $i < $max_thumbs; $i++): 
                                    ?>
                                        <div class="thumbnail-item">
                                            <a href="../<?php echo htmlspecialchars($property_images[$i]); ?>" data-lightbox="property-gallery" data-title="Property Image <?php echo $i+1; ?>">
                                                <img src="../<?php echo htmlspecialchars($property_images[$i]); ?>" class="thumbnail" alt="Property Thumbnail <?php echo $i+1; ?>">
                                            </a>
                                        </div>
                                    <?php endfor; ?>
                                    
                                    <?php if (count($property_images) > 5): ?>
                                        <div class="thumbnail-item position-relative">
                                            <a href="../<?php echo htmlspecialchars($property_images[5]); ?>" data-lightbox="property-gallery" data-title="Property Image 6">
                                                <img src="../<?php echo htmlspecialchars($property_images[5]); ?>" class="thumbnail" alt="Property Thumbnail 6" style="filter: brightness(0.5);">
                                                <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background: rgba(0,0,0,0.5);">
                                                    <span class="text-white fw-bold fs-5">+<?php echo count($property_images) - 5; ?></span>
                                                </div>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Property details card -->
                    <div class="property-header-section">
                        <h2 class="property-title"><?php echo htmlspecialchars($property_data['StreetAddress']); ?></h2>
                        <p class="property-address">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($property_data['City']); ?>, <?php echo htmlspecialchars($property_data['State']); ?> <?php echo htmlspecialchars($property_data['ZIP']); ?>
                        </p>
                        <div class="d-flex align-items-baseline gap-3 mb-2">
                            <h3 class="property-price mb-0">₱<?php echo number_format($property_data['ListingPrice']); ?></h3>
                            <span class="price-label">Listed for <?php echo ucfirst($property_data['Status']); ?></span>
                        </div>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">
                            <i class="far fa-calendar me-1"></i> Listed on <?php echo date('F j, Y', strtotime($property_data['ListingDate'])); ?>
                        </p>
                        
                        <div class="property-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-bed"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo $property_data['Bedrooms']; ?></div>
                                    <div class="stat-label">Bedrooms</div>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-bath"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo $property_data['Bathrooms']; ?></div>
                                    <div class="stat-label">Bathrooms</div>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-ruler-combined"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo number_format($property_data['SquareFootage']); ?></div>
                                    <div class="stat-label">Sq Ft</div>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-home"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo $property_data['YearBuilt']; ?></div>
                                    <div class="stat-label">Year Built</div>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-calculator"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo $price_per_sqft; ?></div>
                                    <div class="stat-label">Per Sq Ft</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Property description -->
                    <div class="info-card">
                        <h3 class="section-title">About This Property</h3>
                        <p class="description-text"><?php echo nl2br(htmlspecialchars($property_data['ListingDescription'])); ?></p>
                    </div>

                    <!-- Property features and amenities -->
                    <div class="info-card">
                        <h3 class="section-title">Property Details</h3>
                        <ul class="feature-list">
                            <li>
                                <span class="feature-label">Property Type</span>
                                <span class="feature-value"><?php echo htmlspecialchars($property_data['PropertyType']); ?></span>
                            </li>
                            <li>
                                <span class="feature-label">Parking</span>
                                <span class="feature-value"><?php echo htmlspecialchars($property_data['ParkingType'] ?? 'Not specified'); ?></span>
                            </li>
                            <li>
                                <span class="feature-label">Lot Size</span>
                                <span class="feature-value"><?php echo !empty($property_data['LotSize']) ? $property_data['LotSize'] . ' acres' : 'Not specified'; ?></span>
                            </li>
                            <li>
                                <span class="feature-label">MLS Number</span>
                                <span class="feature-value"><?php echo htmlspecialchars($property_data['MLSNumber'] ?? 'Not specified'); ?></span>
                            </li>
                            <li>
                                <span class="feature-label">County</span>
                                <span class="feature-value"><?php echo htmlspecialchars($property_data['County'] ?? 'Not specified'); ?></span>
                            </li>
                            <li>
                                <span class="feature-label">Source</span>
                                <span class="feature-value"><?php echo htmlspecialchars($property_data['Source'] ?? 'Not specified'); ?></span>
                            </li>
                        </ul>
                    </div>

                    <!-- Amenities -->
                    <div class="info-card">
                        <h3 class="section-title">Amenities & Features</h3>
                        <?php if (empty($amenities)): ?>
                            <p class="text-muted">No amenities listed</p>
                        <?php else: ?>
                            <div class="amenities-grid">
                                <?php foreach ($amenities as $amenity): ?>
                                    <div class="amenity-item">
                                        <i class="fas fa-check-circle"></i>
                                        <span><?php echo htmlspecialchars($amenity); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right column (sidebar info) -->
                <div class="col-lg-4">
                    <!-- Property metrics card -->
                    <div class="info-card">
                        <h3 class="section-title">Performance Metrics</h3>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="metric-card">
                                    <div class="metric-label">
                                        <i class="fas fa-calendar-day me-1"></i> Days on Market
                                    </div>
                                    <div class="metric-value"><?php echo $days_on_market; ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-card">
                                    <div class="metric-label">
                                        <i class="fas fa-eye me-1"></i> Total Views
                                    </div>
                                    <div class="metric-value"><?php echo number_format($property_data['ViewsCount']); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-card">
                                    <div class="metric-label">
                                        <i class="fas fa-heart me-1"></i> Likes
                                    </div>
                                    <div class="metric-value"><?php echo $property_data['Likes']; ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-card">
                                    <div class="metric-label">
                                        <i class="fas fa-check-circle me-1"></i> Status
                                    </div>
                                    <div class="metric-value" style="font-size: 1.25rem;"><?php echo ucfirst($property_data['approval_status']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Price history card -->
                    <div class="info-card mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="section-title mb-0">Price History</h3>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updatePriceModal" style="border-radius: 8px;">
                                <i class="fas fa-dollar-sign me-1"></i>Update Price
                            </button>
                        </div>
                        <?php if (empty($price_history)): ?>
                            <p class="text-muted">No price change history available</p>
                        <?php else: ?>
                            <div class="price-timeline">
                                <?php foreach (array_reverse($price_history) as $idx => $history): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-marker">
                                            <i class="fas fa-circle"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-header">
                                                <span class="timeline-date"><?php echo $history['event_date']; ?></span>
                                                <span class="timeline-event"><?php echo $history['event_type']; ?></span>
                                            </div>
                                            <div class="timeline-price">
                                                <?php echo $history['price']; ?>
                                                <?php if ($history['change_percentage'] !== null): ?>
                                                    <span class="price-change <?php echo $history['change_class']; ?>">
                                                        <i class="fas fa-<?php echo $history['change_percentage'] > 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                                        <?php echo abs($history['change_percentage']); ?>%
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions card -->
                    <div class="info-card">
                        <h3 class="section-title">Quick Actions</h3>
                        <div class="d-grid gap-3">
                            <?php if ($property_data['approval_status'] === 'approved' && !$is_property_sold): ?>
                                <button type="button" class="btn btn-outline-primary quick-action-btn" data-bs-toggle="modal" data-bs-target="#editPropertyModal">
                                    <i class="fas fa-edit me-2"></i>Edit Property Details
                                </button>
                                
                                <button type="button" class="btn btn-outline-success quick-action-btn" data-bs-toggle="modal" data-bs-target="#updatePriceModal">
                                    <i class="fas fa-dollar-sign me-2"></i>Update Listing Price
                                </button>
                            <?php elseif ($is_property_sold): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-lock me-2"></i>
                                    <strong>Property Sold</strong><br>
                                    This property has been sold and is locked for editing to maintain historical accuracy.
                                </div>
                            <?php endif; ?>
                            
                            <button type="button" class="btn btn-outline-info quick-action-btn" data-bs-toggle="modal" data-bs-target="#tourRequestsModal">
                                <i class="fas fa-calendar-check me-2"></i>View Tour Requests
                            </button>
                            
                            <?php if ($property_data['approval_status'] === 'approved' && !$sale_status): ?>
                                <button type="button" class="btn action-btn" data-bs-toggle="modal" data-bs-target="#markSoldModal"
                                        data-property-id="<?php echo $property_id; ?>"
                                        data-property-title="<?php echo htmlspecialchars($property_data['StreetAddress']); ?>">
                                    <i class="fas fa-check-circle me-2"></i>Mark as Sold
                                </button>
                            <?php endif; ?>
                            
                            <a href="property_analytics.php?id=<?php echo $property_id; ?>" class="btn btn-outline-secondary quick-action-btn">
                                <i class="fas fa-chart-bar me-2"></i>View Full Analytics
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit/Update Property Modal -->
    <div class="modal fade" id="editPropertyModal" tabindex="-1" aria-labelledby="editPropertyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content edit-modal-enhanced">
                <div class="modal-header edit-modal-header">
                    <div class="d-flex align-items-center">
                        <div class="modal-icon-wrapper">
                            <i class="fas fa-pen-to-square"></i>
                        </div>
                        <div class="ms-3">
                            <h5 class="modal-title mb-0" id="editPropertyModalLabel">Update Property Details</h5>
                            <p class="modal-subtitle mb-0">Modify listing information and manage photos</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body edit-modal-body">
                    <div id="updateAlert" class="alert-enhanced d-none" role="alert"></div>
                    <form id="editPropertyForm" novalidate>
                        <input type="hidden" name="property_id" value="<?php echo (int)$property_id; ?>" />
                        
                        <!-- Progress Indicator -->
                        <div class="edit-progress-bar mb-4">
                            <div class="progress-step active" data-step="1">
                                <div class="step-circle"><i class="fas fa-home"></i></div>
                                <span class="step-label">Basic Info</span>
                            </div>
                            <div class="progress-line"></div>
                            <div class="progress-step" data-step="2">
                                <div class="step-circle"><i class="fas fa-ruler-combined"></i></div>
                                <span class="step-label">Specs</span>
                            </div>
                            <div class="progress-line"></div>
                            <div class="progress-step" data-step="3">
                                <div class="step-circle"><i class="fas fa-images"></i></div>
                                <span class="step-label">Photos</span>
                            </div>
                            <div class="progress-line"></div>
                            <div class="progress-step" data-step="4">
                                <div class="step-circle"><i class="fas fa-align-left"></i></div>
                                <span class="step-label">Description</span>
                            </div>
                        </div>

                        <div class="row g-4">
                            <!-- Left column -->
                            <div class="col-lg-6">
                                <div class="edit-card" data-aos="fade-right">
                                    <div class="edit-card-header">
                                        <div class="card-icon"><i class="fas fa-home"></i></div>
                                        <h6 class="card-title">Basic Information</h6>
                                    </div>
                                    <div class="edit-card-body">
                                        <div class="form-group-enhanced mb-3">
                                            <label class="form-label-enhanced"><i class="fas fa-map-marker-alt me-2"></i>Street Address</label>
                                            <input type="text" class="form-control-enhanced" name="StreetAddress" value="<?php echo htmlspecialchars($property_data['StreetAddress']); ?>" required>
                                            <div class="invalid-feedback">Street address is required.</div>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-city me-2"></i>City</label>
                                                    <input type="text" class="form-control-enhanced" name="City" value="<?php echo htmlspecialchars($property_data['City']); ?>" required>
                                                    <div class="invalid-feedback">City is required.</div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-flag me-2"></i>State</label>
                                                    <input type="text" class="form-control-enhanced" name="State" value="<?php echo htmlspecialchars($property_data['State']); ?>" required>
                                                    <div class="invalid-feedback">State is required.</div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-mail-bulk me-2"></i>ZIP</label>
                                                    <input type="text" class="form-control-enhanced" name="ZIP" value="<?php echo htmlspecialchars($property_data['ZIP']); ?>" required>
                                                    <div class="invalid-feedback">ZIP is required.</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row g-3 mt-2">
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-peso-sign me-2"></i>Listing Price (₱)</label>
                                                    <div class="input-icon-wrapper">
                                                        <span class="input-icon">₱</span>
                                                        <input type="number" min="0" step="0.01" class="form-control-enhanced ps-5" name="ListingPrice" value="<?php echo htmlspecialchars($property_data['ListingPrice']); ?>" required>
                                                    </div>
                                                    <div class="invalid-feedback">Enter a valid price.</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-calendar me-2"></i>Listing Date</label>
                                                    <input type="date" class="form-control-enhanced" name="ListingDate" value="<?php echo htmlspecialchars(date('Y-m-d', strtotime($property_data['ListingDate']))); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                                    <div class="invalid-feedback">Enter a valid date (not in the future).</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="edit-card mt-3" data-aos="fade-right" data-aos-delay="100">
                                    <div class="edit-card-header">
                                        <div class="card-icon"><i class="fas fa-list-ul"></i></div>
                                        <h6 class="card-title">Property Specifications</h6>
                                    </div>
                                    <div class="edit-card-body">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-bed me-2"></i>Bedrooms</label>
                                                    <input type="number" min="0" step="1" class="form-control-enhanced" name="Bedrooms" value="<?php echo (int)$property_data['Bedrooms']; ?>" required>
                                                    <div class="invalid-feedback">Enter bedrooms (0+).</div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-bath me-2"></i>Bathrooms</label>
                                                    <input type="number" min="0" step="0.5" class="form-control-enhanced" name="Bathrooms" value="<?php echo htmlspecialchars($property_data['Bathrooms']); ?>" required>
                                                    <div class="invalid-feedback">Enter bathrooms (0+).</div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-ruler-combined me-2"></i>Square Footage</label>
                                                    <input type="number" min="0" step="1" class="form-control-enhanced" name="SquareFootage" value="<?php echo (int)$property_data['SquareFootage']; ?>" required>
                                                    <div class="invalid-feedback">Enter area (0+).</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row g-3 mt-2">
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-calendar-alt me-2"></i>Year Built</label>
                                                    <input type="number" min="1800" max="<?php echo date('Y'); ?>" class="form-control-enhanced" name="YearBuilt" value="<?php echo (int)$property_data['YearBuilt']; ?>">
                                                    <div class="invalid-feedback">Enter a valid year.</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-expand-arrows-alt me-2"></i>Lot Size (acres)</label>
                                                    <input type="number" min="0" step="0.01" class="form-control-enhanced" name="LotSize" value="<?php echo htmlspecialchars($property_data['LotSize'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right column -->
                            <div class="col-lg-6">
                                <div class="edit-card" data-aos="fade-left">
                                    <div class="edit-card-header">
                                        <div class="card-icon"><i class="fas fa-tags"></i></div>
                                        <h6 class="card-title">Classification & Details</h6>
                                    </div>
                                    <div class="edit-card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-building me-2"></i>Property Type</label>
                                                    <select class="form-select-enhanced" name="PropertyType" required>
                                                <?php 
                                                    $types = ['House','Condo','Townhouse','Apartment','Land','Other'];
                                                    $cur = trim((string)$property_data['PropertyType']);
                                                    foreach ($types as $t) {
                                                        $sel = strcasecmp($cur,$t)===0 ? 'selected' : '';
                                                        echo '<option value="'.htmlspecialchars($t).'" '.$sel.'>'.htmlspecialchars($t).'</option>';
                                                    }
                                                ?>
                                            </select>
                                            <div class="invalid-feedback">Select a property type.</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-car me-2"></i>Parking Type</label>
                                                    <select class="form-select-enhanced" name="ParkingType">
                                                <?php 
                                                    $parks = ['Garage','Street','Driveway','Carport','None','Other'];
                                                    $curP = trim((string)($property_data['ParkingType'] ?? ''));
                                                    foreach ($parks as $p) {
                                                        $sel = strcasecmp($curP,$p)===0 ? 'selected' : '';
                                                        echo '<option value="'.htmlspecialchars($p).'" '.$sel.'>'.htmlspecialchars($p).'</option>';
                                                    }
                                                ?>
                                            </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row g-3 mt-2">
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-hashtag me-2"></i>MLS Number</label>
                                                    <input type="text" class="form-control-enhanced" name="MLSNumber" value="<?php echo htmlspecialchars($property_data['MLSNumber'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-map-marked-alt me-2"></i>County</label>
                                                    <input type="text" class="form-control-enhanced" name="County" value="<?php echo htmlspecialchars($property_data['County'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row g-3 mt-2">
                                            <div class="col-md-12">
                                                <div class="form-group-enhanced">
                                                    <label class="form-label-enhanced"><i class="fas fa-database me-2"></i>Source</label>
                                                    <input type="text" class="form-control-enhanced" name="Source" value="<?php echo htmlspecialchars($property_data['Source'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Manage Photos -->
                                <div class="edit-card mt-3" data-aos="fade-left" data-aos-delay="100">
                                    <div class="edit-card-header">
                                        <div class="card-icon"><i class="fas fa-images"></i></div>
                                        <h6 class="card-title">Photo Gallery Manager</h6>
                                    </div>
                                    <div class="edit-card-body">
                                        <div class="upload-zone mb-4">
                                            <label for="photoUploadInput" class="upload-label">
                                                <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                                <div class="upload-text">
                                                    <span class="upload-title">Click to upload or drag & drop</span>
                                                    <span class="upload-subtitle">JPEG, PNG, GIF up to 5MB each</span>
                                                </div>
                                            </label>
                                            <input type="file" id="photoUploadInput" class="upload-input" accept="image/*" multiple>
                                        </div>
                                        <div id="photosAlert" class="alert-enhanced d-none" role="alert"></div>
                                        <div id="photosGrid" class="photos-grid">
                                            <?php foreach ($property_images as $idx => $img): ?>
                                                <div class="photo-item" data-url="<?php echo htmlspecialchars($img); ?>">
                                                    <div class="photo-wrapper">
                                                        <img src="../<?php echo htmlspecialchars($img); ?>" alt="Photo <?php echo $idx+1; ?>" class="photo-img">
                                                        <?php if ($idx === 0): ?>
                                                            <div class="cover-badge">
                                                                <i class="fas fa-star me-1"></i>Cover Photo
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="photo-overlay">
                                                            <div class="photo-actions">
                                                                <button type="button" class="photo-btn btn-move-left" title="Move left">
                                                                    <i class="fas fa-arrow-left"></i>
                                                                </button>
                                                                <button type="button" class="photo-btn btn-set-cover" title="Set as cover">
                                                                    <i class="fas fa-star"></i>
                                                                </button>
                                                                <button type="button" class="photo-btn btn-move-right" title="Move right">
                                                                    <i class="fas fa-arrow-right"></i>
                                                                </button>
                                                                <button type="button" class="photo-btn btn-delete btn-delete-photo" title="Delete">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="d-flex justify-content-end mt-4 gap-2">
                                            <button type="button" id="savePhotoOrderBtn" class="btn-enhanced btn-secondary-enhanced">
                                                <i class="fas fa-save me-2"></i>Save Photo Order
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="edit-card mt-3" data-aos="fade-left" data-aos-delay="200">
                                    <div class="edit-card-header">
                                        <div class="card-icon"><i class="fas fa-align-left"></i></div>
                                        <h6 class="card-title">Property Description</h6>
                                    </div>
                                    <div class="edit-card-body">
                                        <div class="form-group-enhanced">
                                            <label class="form-label-enhanced"><i class="fas fa-pen-fancy me-2"></i>Listing Description</label>
                                            <textarea class="textarea-enhanced" name="ListingDescription" rows="8" minlength="20" placeholder="Describe the property features, location highlights, and unique selling points..." required><?php echo htmlspecialchars($property_data['ListingDescription']); ?></textarea>
                                            <div class="invalid-feedback">Please provide at least 20 characters.</div>
                                            <div class="char-counter">
                                                <span id="charCount">0</span> / 1000 characters
                                            </div>
                                        </div>
                                        <div class="info-box mt-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <span>Listing Status is managed by workflow. Use "Mark as Sold" for sale updates.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Modal Footer Actions -->
                        <div class="modal-footer-enhanced">
                            <button type="button" class="btn-enhanced btn-cancel" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn-enhanced btn-save">
                                <i class="fas fa-check me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Full Gallery Modal -->
    <div class="modal fade" id="galleryModal" tabindex="-1" aria-labelledby="galleryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="galleryModalLabel">Property Gallery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <?php foreach ($property_images as $index => $image): ?>
                            <div class="col-md-4 col-6">
                                <a href="../<?php echo htmlspecialchars($image); ?>" data-lightbox="property-gallery-modal">
                                    <img src="../<?php echo htmlspecialchars($image); ?>" class="img-fluid rounded" alt="Property Image <?php echo $index + 1; ?>">
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mark as Sold Modal -->
    <div class="modal fade" id="markSoldModal" tabindex="-1" aria-labelledby="markSoldModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="markSoldModalLabel">Mark Property as Sold</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form action="mark_as_sold_process.php" method="POST" enctype="multipart/form-data" id="markSoldForm">
                        <input type="hidden" name="property_id" id="propertyId" value="<?php echo $property_id; ?>">
                        
                        <div class="mb-3">
                            <p>You are about to mark the following property as sold:</p>
                            <p class="fw-bold" id="propertyTitle"><?php echo htmlspecialchars($property_data['StreetAddress']); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label for="sale_price" class="form-label">Sale Price (₱)</label>
                            <input type="number" step="0.01" class="form-control" id="sale_price" name="sale_price" placeholder="Enter the final sale price" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="sale_date" class="form-label">Sale Date</label>
                            <input type="date" class="form-control" id="sale_date" name="sale_date" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="buyer_name" class="form-label">Buyer's Name</label>
                            <input type="text" class="form-control" id="buyer_name" name="buyer_name" placeholder="Enter the buyer's full name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="buyer_contact" class="form-label">Buyer's Contact</label>
                            <input type="text" class="form-control" id="buyer_contact" name="buyer_contact" placeholder="Enter the buyer's phone number or email">
                        </div>
                        
                        <div class="mb-3">
                            <label for="sale_documents" class="form-label">Sale Documents</label>
                            <input type="file" class="form-control" id="sale_documents" name="sale_documents[]" multiple required>
                            <div class="form-text">Upload document(s) as proof of sale (e.g., contract, deed, or receipt). You can select multiple files.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="additional_notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="additional_notes" name="additional_notes" rows="3" placeholder="Add any extra information about the sale (optional)"></textarea>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn" style="background-color: #bc9e42; color: white;">Submit for Verification</button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <?php include 'logout_agent_modal.php'; ?>

    <!-- Update Price Modal -->
    <div class="modal fade" id="updatePriceModal" tabindex="-1" aria-labelledby="updatePriceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content edit-modal-enhanced">
                <div class="modal-header edit-modal-header">
                    <div class="d-flex align-items-center">
                        <div class="modal-icon-wrapper">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="ms-3">
                            <h5 class="modal-title mb-0" id="updatePriceModalLabel">Update Listing Price</h5>
                            <p class="modal-subtitle mb-0">Adjust the property price</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="background: linear-gradient(135deg, #fafafa 0%, #f5f5f5 100%); padding: 2rem;">
                    <div id="priceUpdateAlert" class="alert-enhanced d-none" role="alert"></div>
                    <form id="updatePriceForm">
                        <input type="hidden" name="property_id" value="<?php echo (int)$property_id; ?>">
                        
                        <div class="current-price-display mb-4">
                            <label class="form-label-enhanced mb-2">Current Price</label>
                            <div class="current-price-box">
                                <span class="currency">₱</span>
                                <span class="amount"><?php echo number_format($property_data['ListingPrice'], 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="form-group-enhanced mb-4">
                            <label class="form-label-enhanced"><i class="fas fa-peso-sign me-2"></i>New Listing Price (₱)</label>
                            <div class="input-icon-wrapper">
                                <span class="input-icon">₱</span>
                                <input type="number" min="0" step="0.01" class="form-control-enhanced ps-5" name="new_price" id="newPriceInput" placeholder="0.00" required>
                            </div>
                            <div class="invalid-feedback">Please enter a valid price.</div>
                        </div>
                        
                        <div class="price-change-preview mb-4" id="priceChangePreview" style="display: none;">
                            <div class="preview-box">
                                <div class="preview-label">Price Change</div>
                                <div class="preview-value" id="priceChangeValue"></div>
                            </div>
                        </div>
                        
                        <div class="form-group-enhanced mb-4">
                            <label class="form-label-enhanced"><i class="fas fa-comment me-2"></i>Reason for Price Change (Optional)</label>
                            <textarea class="textarea-enhanced" name="reason" rows="3" placeholder="Market adjustment, renovation completed, seasonal pricing, etc."></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn-enhanced btn-cancel" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn-enhanced btn-save">
                                <i class="fas fa-check me-2"></i>Update Price
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Tour Requests Modal -->
    <div class="modal fade" id="tourRequestsModal" tabindex="-1" aria-labelledby="tourRequestsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content edit-modal-enhanced">
                <div class="modal-header edit-modal-header">
                    <div class="d-flex align-items-center">
                        <div class="modal-icon-wrapper">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="ms-3">
                            <h5 class="modal-title mb-0" id="tourRequestsModalLabel">Tour Requests</h5>
                            <p class="modal-subtitle mb-0">Manage property tour requests</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body edit-modal-body">
                    <div id="tourRequestsContent">
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-3 text-muted">Loading tour requests...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize lightbox
            lightbox.option({
                'resizeDuration': 200,
                'wrapAround': true,
                'showImageNumberLabel': false
            });
            
            // Initialize map with approximate location (for privacy)
            try {
                // Define default coordinates (Cagayan de Oro city center)
                const defaultLat = 8.4542;
                const defaultLng = 124.6319;
                
                // Initialize the map
                const map = L.map('propertyMap').setView([defaultLat, defaultLng], 14);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                
                // Add marker for the property (approximate location for privacy)
                L.marker([defaultLat, defaultLng]).addTo(map)
                    .bindPopup("<?php echo htmlspecialchars($property_data['City'] ?: 'Area'); ?>");
            } catch (e) {
                console.error("Error initializing map:", e);
                document.getElementById('propertyMap').innerHTML = 
                    '<div class="alert alert-warning">Map could not be loaded. Please try again later.</div>';
            }
            
            // Mark as Sold Modal handler
            const markSoldModal = document.getElementById('markSoldModal');
            if (markSoldModal) {
                markSoldModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const propertyId = button.getAttribute('data-property-id');
                    const propertyTitle = button.getAttribute('data-property-title');
                    
                    document.getElementById('propertyId').value = propertyId;
                    document.getElementById('propertyTitle').textContent = propertyTitle;
                });
            }
        });

                // Edit Property: Client-side validation + AJAX submit
                (function(){
                    const form = document.getElementById('editPropertyForm');
                    if (!form) return;
                    const alertBox = document.getElementById('updateAlert');

                    function showAlert(ok, msg) {
                        alertBox.classList.remove('d-none','alert-success','alert-danger','alert-enhanced');
                        alertBox.classList.add('alert-enhanced', ok ? 'alert-success' : 'alert-danger');
                        alertBox.innerHTML = (ok ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-triangle"></i>') + '<span>' + (msg || (ok?'Updated successfully.':'Please fix the errors and try again.')) + '</span>';
                    }
                    
                    // Character counter for description
                    const descTextarea = form.querySelector('[name="ListingDescription"]');
                    const charCountEl = document.getElementById('charCount');
                    if (descTextarea && charCountEl) {
                        descTextarea.addEventListener('input', function() {
                            const count = this.value.length;
                            charCountEl.textContent = count;
                            if (count > 1000) {
                                charCountEl.style.color = '#dc3545';
                            } else if (count > 800) {
                                charCountEl.style.color = '#ffc107';
                            } else {
                                charCountEl.style.color = '#999';
                            }
                        });
                        // Initial count
                        charCountEl.textContent = descTextarea.value.length;
                    }

                    form.addEventListener('submit', function(e){
                        e.preventDefault();
                        // HTML5 validity
                        if (!form.checkValidity()) {
                            form.classList.add('was-validated');
                            showAlert(false, 'Please fill in all required fields correctly.');
                            return;
                        }

                        const formData = new FormData(form);
                        // Additional simple validations
                        const price = parseFloat(formData.get('ListingPrice')||'0');
                        const beds = parseInt(formData.get('Bedrooms')||'0',10);
                        const baths = parseFloat(formData.get('Bathrooms')||'0');
                        const sqft = parseInt(formData.get('SquareFootage')||'0',10);
                        const year = parseInt(formData.get('YearBuilt')||'0',10);
                        const desc = String(formData.get('ListingDescription')||'');
                        const dateStr = String(formData.get('ListingDate')||'');
                        const today = new Date().toISOString().slice(0,10);
                        let msg = '';
                        if (price < 0 || isNaN(price)) msg = 'Listing price must be a positive number.';
                        else if (beds < 0 || isNaN(beds)) msg = 'Bedrooms must be 0 or more.';
                        else if (baths < 0 || isNaN(baths)) msg = 'Bathrooms must be 0 or more.';
                        else if (sqft < 0 || isNaN(sqft)) msg = 'Square footage must be 0 or more.';
                        else if (year && (year < 1800 || year > (new Date()).getFullYear())) msg = 'Year built must be between 1800 and current year.';
                        else if (!dateStr || dateStr > today) msg = 'Listing date cannot be in the future.';
                        else if (desc.trim().length < 20) msg = 'Description must be at least 20 characters.';
                        if (msg) { showAlert(false, msg); return; }

                        // Disable submit
                        const submitBtn = form.querySelector('button[type="submit"]');
                        const origHtml = submitBtn.innerHTML;
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

                        fetch('update_property_process.php', {
                            method: 'POST',
                            body: new URLSearchParams(Array.from(formData.entries())),
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data && data.success) {
                                showAlert(true, data.message || 'Property updated successfully. Reloading...');
                                setTimeout(() => { window.location.reload(); }, 900);
                            } else {
                                showAlert(false, data && data.message ? data.message : 'Update failed.');
                                if (data && data.errors) {
                                    // Highlight fields with errors
                                    Object.keys(data.errors).forEach(k => {
                                        const el = form.querySelector('[name="'+k+'"]');
                                        if (el) {
                                            el.classList.add('is-invalid');
                                            const fb = el.closest('.mb-3, .col-md-6, .col-md-4, .col-md-12')?.querySelector('.invalid-feedback');
                                            if (fb) fb.textContent = data.errors[k];
                                        }
                                    });
                                }
                            }
                        })
                        .catch(() => showAlert(false, 'Unexpected error. Please try again.'))
                        .finally(() => { submitBtn.disabled = false; submitBtn.innerHTML = origHtml; });
                    });
                })();

                // Photos management logic
                (function(){
                    const propId = <?php echo (int)$property_id; ?>;
                    const grid = document.getElementById('photosGrid');
                    const uploadInput = document.getElementById('photoUploadInput');
                    const orderBtn = document.getElementById('savePhotoOrderBtn');
                    const alertEl = document.getElementById('photosAlert');

                    function showPhotosAlert(ok, msg){
                        alertEl.classList.remove('d-none','alert-success','alert-danger','alert-enhanced');
                        alertEl.classList.add('alert-enhanced', ok ? 'alert-success' : 'alert-danger');
                        alertEl.innerHTML = (ok?'<i class="fas fa-check-circle"></i>':'<i class="fas fa-exclamation-triangle"></i>') + '<span>' + (msg||'') + '</span>';
                    }

                    function getOrder(){
                        return Array.from(grid.querySelectorAll('[data-url]')).map(card => card.getAttribute('data-url'));
                    }

                    function refreshCoverBadge(){
                        // Optional: visually mark first image as cover
                        const cards = grid.querySelectorAll('[data-url] .card');
                        cards.forEach((card,idx)=>{
                            card.querySelectorAll('.cover-badge')?.forEach(b=>b.remove());
                            if(idx===0){
                                const span = document.createElement('span');
                                span.className = 'cover-badge badge bg-warning text-dark position-absolute m-2';
                                span.textContent = 'Cover';
                                card.style.position = 'relative';
                                card.appendChild(span);
                            }
                        });
                    }

                    function reflow(){
                        // Ensure buttons disabled at bounds
                        const items = Array.from(grid.querySelectorAll('[data-url]'));
                        items.forEach((wrap, idx)=>{
                            wrap.querySelector('.btn-move-left').disabled = (idx===0);
                            wrap.querySelector('.btn-move-right').disabled = (idx===items.length-1);
                        });
                        refreshCoverBadge();
                    }

                    function attachHandlers(container){
                        container.addEventListener('click', function(e){
                            const col = e.target.closest('[data-url]');
                            if(!col) return;
                            const url = col.getAttribute('data-url');
                            if(e.target.closest('.btn-move-left')){
                                const prev = col.previousElementSibling;
                                if(prev){ col.parentNode.insertBefore(col, prev); reflow(); }
                            } else if(e.target.closest('.btn-move-right')){
                                const next = col.nextElementSibling;
                                if(next){ col.parentNode.insertBefore(next, col); reflow(); }
                            } else if(e.target.closest('.btn-set-cover')){
                                // Move to first position
                                const first = grid.querySelector('[data-url]');
                                if(first !== col){ grid.insertBefore(col, first); reflow(); saveOrder('Cover set'); }
                            } else if(e.target.closest('.btn-delete-photo')){
                                if(confirm('Delete this photo?')){
                                    deletePhoto(url, col);
                                }
                            }
                        });
                    }

                    function saveOrder(successMsg){
                        const ordered = getOrder();
                        fetch('reorder_property_images.php', {
                            method: 'POST',
                            headers: {'Content-Type':'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({ property_id: String(propId), order: JSON.stringify(ordered) })
                        }).then(r=>r.json()).then(data=>{
                            if(data && data.success){
                                showPhotosAlert(true, successMsg || 'Photo order saved.');
                                reflow();
                            } else {
                                showPhotosAlert(false, data?.message || 'Failed to save order.');
                            }
                        }).catch(()=> showPhotosAlert(false, 'Network error saving order.'));
                    }

                    function deletePhoto(url, node){
                        const params = new URLSearchParams({ property_id: String(propId), photo_url: url });
                        fetch('delete_property_image.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params })
                        .then(r=>r.json()).then(data=>{
                            if(data && data.success){
                                node.remove();
                                showPhotosAlert(true, 'Photo deleted.');
                                reflow();
                            } else {
                                showPhotosAlert(false, data?.message || 'Failed to delete photo.');
                            }
                        }).catch(()=> showPhotosAlert(false, 'Network error deleting photo.'));
                    }

                    if(orderBtn){ orderBtn.addEventListener('click', ()=> saveOrder()); }
                    if(grid){ attachHandlers(grid); reflow(); }

                    if(uploadInput){
                        uploadInput.addEventListener('change', function(){
                            const files = Array.from(uploadInput.files||[]);
                            if(!files.length) return;
                            const formData = new FormData();
                            formData.append('property_id', String(propId));
                            files.forEach(f=> formData.append('images[]', f));

                            fetch('upload_property_image.php', { method:'POST', body: formData })
                            .then(r=>r.json()).then(data=>{
                                if(data && data.success && Array.isArray(data.photos)){
                                    // Append new cards
                                    data.photos.forEach(photo => {
                                        const col = document.createElement('div');
                                        col.className = 'col-6 col-md-4 col-lg-3';
                                        col.setAttribute('data-url', photo.url);
                                        col.innerHTML = `
                                            <div class="card h-100 shadow-sm">
                                                <img src="../${photo.url}" class="card-img-top" alt="Photo" style="object-fit:cover;height:140px;">
                                                <div class="card-body p-2 d-flex align-items-center justify-content-between">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary me-1 btn-move-left" title="Move left"><i class="fas fa-arrow-left"></i></button>
                                                    <button type="button" class="btn btn-sm btn-outline-warning me-1 btn-set-cover" title="Set as cover"><i class="fas fa-star"></i></button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary me-1 btn-move-right" title="Move right"><i class="fas fa-arrow-right"></i></button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-photo" title="Delete"><i class="fas fa-trash"></i></button>
                                                </div>
                                            </div>`;
                                        grid.appendChild(col);
                                    });
                                    showPhotosAlert(true, `${data.photos.length} photo(s) uploaded.`);
                                    reflow();
                                    uploadInput.value = '';
                                } else {
                                    showPhotosAlert(false, data?.message || 'Upload failed.');
                                }
                            }).catch(()=> showPhotosAlert(false, 'Network error uploading photos.'));
                        });
                    }
                })();
                
                // Update Price Modal Logic
                (function(){
                    const form = document.getElementById('updatePriceForm');
                    const newPriceInput = document.getElementById('newPriceInput');
                    const currentPrice = <?php echo (float)$property_data['ListingPrice']; ?>;
                    const previewBox = document.getElementById('priceChangePreview');
                    const previewValue = document.getElementById('priceChangeValue');
                    const alertBox = document.getElementById('priceUpdateAlert');
                    
                    function showPriceAlert(ok, msg){
                        alertBox.classList.remove('d-none','alert-success','alert-danger','alert-enhanced');
                        alertBox.classList.add('alert-enhanced', ok ? 'alert-success' : 'alert-danger');
                        alertBox.innerHTML = (ok?'<i class="fas fa-check-circle"></i>':'<i class="fas fa-exclamation-triangle"></i>') + '<span>' + (msg||'') + '</span>';
                    }
                    
                    if (newPriceInput) {
                        newPriceInput.addEventListener('input', function(){
                            const newPrice = parseFloat(this.value) || 0;
                            if (newPrice > 0 && newPrice !== currentPrice) {
                                const diff = newPrice - currentPrice;
                                const percent = ((diff / currentPrice) * 100).toFixed(2);
                                const isIncrease = diff > 0;
                                
                                previewBox.style.display = 'block';
                                previewBox.querySelector('.preview-box').classList.toggle('decrease', !isIncrease);
                                
                                const arrow = isIncrease ? 'arrow-up' : 'arrow-down';
                                const sign = isIncrease ? '+' : '';
                                const color = isIncrease ? '#28a745' : '#dc3545';
                                
                                previewValue.innerHTML = `
                                    <i class="fas fa-${arrow}" style="color: ${color}"></i>
                                    <span style="color: ${color}">${sign}₱${Math.abs(diff).toLocaleString('en-US', {minimumFractionDigits: 2})} (${sign}${percent}%)</span>
                                `;
                            } else {
                                previewBox.style.display = 'none';
                            }
                        });
                    }
                    
                    if (form) {
                        form.addEventListener('submit', function(e){
                            e.preventDefault();
                            const formData = new FormData(form);
                            const newPrice = parseFloat(formData.get('new_price')) || 0;
                            
                            if (newPrice <= 0) {
                                showPriceAlert(false, 'Please enter a valid price.');
                                return;
                            }
                            
                            if (newPrice === currentPrice) {
                                showPriceAlert(false, 'New price is the same as current price.');
                                return;
                            }
                            
                            const submitBtn = form.querySelector('button[type="submit"]');
                            const origHtml = submitBtn.innerHTML;
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
                            
                            fetch('update_price_process.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams(Array.from(formData.entries()))
                            })
                            .then(async r => {
                                const text = await r.text();
                                if (!r.ok) {
                                    console.error('Price update HTTP error', r.status, text);
                                    throw new Error(text || ('HTTP ' + r.status));
                                }
                                try { return JSON.parse(text); }
                                catch(e){
                                    console.error('Price update invalid JSON:', text);
                                    throw e;
                                }
                            })
                            .then(data => {
                                if (data && data.success) {
                                    showPriceAlert(true, data.message || 'Price updated successfully! Reloading...');
                                    setTimeout(() => window.location.reload(), 1200);
                                } else {
                                    showPriceAlert(false, data?.message || 'Failed to update price.');
                                }
                            })
                            .catch(err => {
                                console.error('Price update error:', err);
                                showPriceAlert(false, 'Network error. Please try again.');
                            })
                            .finally(() => {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = origHtml;
                            });
                        });
                    }
                })();
                
                // Tour Requests Modal Logic
                (function(){
                    const modal = document.getElementById('tourRequestsModal');
                    const content = document.getElementById('tourRequestsContent');
                    const propertyId = <?php echo (int)$property_id; ?>;
                    
                    if (modal) {
                        modal.addEventListener('show.bs.modal', function(){
                            // Load tour requests
                            fetch(`get_property_tour_requests.php?property_id=${propertyId}`)
                            .then(r => {
                                if (!r.ok) throw new Error('HTTP error ' + r.status);
                                return r.json();
                            })
                            .then(data => {
                                if (data && data.success) {
                                    if (data.requests && data.requests.length > 0) {
                                        let html = '<div class="tour-requests-list">';
                                        data.requests.forEach(req => {
                                            const statusColors = {
                                                'Pending': 'bg-warning text-dark',
                                                'Confirmed': 'bg-info text-white',
                                                'Completed': 'bg-success text-white',
                                                'Cancelled': 'bg-secondary text-white',
                                                'Rejected': 'bg-danger text-white'
                                            };
                                            const statusClass = statusColors[req.request_status] || 'bg-secondary text-white';
                                            const initials = (req.user_name || 'U').charAt(0).toUpperCase();
                                            
                                            html += `
                                                <div class="tour-request-card">
                                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                                        <div class="requester-info flex-grow-1">
                                                            <div class="requester-avatar">${initials}</div>
                                                            <div class="requester-details">
                                                                <h6>${req.user_name || 'User'}</h6>
                                                                <p><i class="fas fa-envelope me-1"></i>${req.user_email || 'N/A'}</p>
                                                                ${req.user_phone ? `<p><i class="fas fa-phone me-1"></i>${req.user_phone}</p>` : ''}
                                                            </div>
                                                        </div>
                                                        <span class="tour-status-badge ${statusClass}">${req.request_status}</span>
                                                    </div>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <div class="info-item">
                                                                <i class="fas fa-calendar text-primary me-2"></i>
                                                                <strong>Preferred Date:</strong> ${req.preferred_date || 'Not specified'}
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="info-item">
                                                                <i class="fas fa-clock text-primary me-2"></i>
                                                                <strong>Preferred Time:</strong> ${req.preferred_time || 'Not specified'}
                                                            </div>
                                                        </div>
                                                        <div class="col-12">
                                                            <div class="info-item">
                                                                <i class="fas fa-comment text-primary me-2"></i>
                                                                <strong>Message:</strong> ${req.message || 'No message provided'}
                                                            </div>
                                                        </div>
                                                        <div class="col-12">
                                                            <small class="text-muted">
                                                                <i class="fas fa-info-circle me-1"></i>
                                                                Requested on ${req.request_date || 'N/A'}
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                            `;
                                        });
                                        html += '</div>';
                                        content.innerHTML = html;
                                    } else {
                                        content.innerHTML = `
                                            <div class="text-center py-5">
                                                <i class="fas fa-calendar-times" style="font-size: 4rem; color: var(--secondary-gray); opacity: 0.3;"></i>
                                                <p class="mt-3 text-muted">No tour requests yet for this property.</p>
                                            </div>
                                        `;
                                    }
                                } else {
                                    content.innerHTML = `
                                        <div class="alert alert-danger">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            ${data?.message || 'Failed to load tour requests.'}
                                        </div>
                                    `;
                                }
                            })
                            .catch(err => {
                                console.error('Tour requests error:', err);
                                content.innerHTML = `
                                    <div class="alert alert-danger">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Network error. Please try again.
                                    </div>
                                `;
                            });
                        });
                    }
                })();
    </script>
</body>
</html>