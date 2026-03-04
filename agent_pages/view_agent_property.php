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

// Fetch agent info for navbar
$sql_agent_info = "SELECT first_name, last_name, username FROM accounts WHERE account_id = ?";
$stmt_agent_info = $conn->prepare($sql_agent_info);
$stmt_agent_info->bind_param("i", $agent_account_id);
$stmt_agent_info->execute();
$result_agent_info = $stmt_agent_info->get_result();
$agent_info = $result_agent_info->fetch_assoc();
$stmt_agent_info->close();
$agent_username = $agent_info['username'] ?? 'Agent';

// Fetch profile picture from agent_information table
$sql_profile = "SELECT profile_picture_url FROM agent_information WHERE account_id = ?";
$stmt_profile = $conn->prepare($sql_profile);
$stmt_profile->bind_param("i", $agent_account_id);
$stmt_profile->execute();
$result_profile = $stmt_profile->get_result();
$profile_data = $result_profile->fetch_assoc();
$stmt_profile->close();
if ($profile_data && !empty($profile_data['profile_picture_url'])) {
    $agent_info['profile_picture_url'] = $profile_data['profile_picture_url'];
}

$property_data = null;
$property_images = [];
$floor_images = [];
$amenities = [];
$price_history = [];
$rental_details = null;
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

if (!$property_data) {
    header("Location: agent_property.php");
    exit();
}

// Verify if this property belongs to the current agent
$sql_check_owner = "SELECT 1 FROM property_log WHERE property_id = ? AND account_id = ? LIMIT 1";
$stmt_check = $conn->prepare($sql_check_owner);
$stmt_check->bind_param("ii", $property_id, $agent_account_id);
$stmt_check->execute();
$is_owner = $stmt_check->get_result()->num_rows > 0;
$stmt_check->close();

// If rental, fetch rental details
if (isset($property_data['Status']) && trim($property_data['Status']) === 'For Rent') {
    $rd_sql = "SELECT monthly_rent, security_deposit, lease_term_months, furnishing, available_from FROM rental_details WHERE property_id = ? LIMIT 1";
    $stmt_rd = $conn->prepare($rd_sql);
    $stmt_rd->bind_param("i", $property_id);
    $stmt_rd->execute();
    $rd_res = $stmt_rd->get_result();
    if ($rd_res->num_rows > 0) {
        $rental_details = $rd_res->fetch_assoc();
    }
    $stmt_rd->close();
}

// Fetch price history
$sql_history = "SELECT event_date, event_type, price FROM price_history WHERE property_id = ? ORDER BY event_date DESC, history_id DESC";
$stmt_history = $conn->prepare($sql_history);
$stmt_history->bind_param("i", $property_id);
$stmt_history->execute();
$history_result = $stmt_history->get_result();
$price_history_raw = $history_result->fetch_all(MYSQLI_ASSOC);
$stmt_history->close();

$price_history = [];
for ($i = 0; $i < count($price_history_raw); $i++) {
    $current_event = $price_history_raw[$i];
    $previous_price = isset($price_history_raw[$i + 1]) ? $price_history_raw[$i + 1]['price'] : null;
    $change_percentage = null;
    $change_class = '';
    if ($previous_price && $previous_price > 0) {
        $change = (($current_event['price'] - $previous_price) / $previous_price) * 100;
        $change_percentage = round($change, 2);
        if ($change > 0) $change_class = 'text-success';
        elseif ($change < 0) $change_class = 'text-danger';
    }
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

// Fetch floor images grouped by floor number
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
    
    // Ensure the photo_url has the correct path
    $photo_url = $row['photo_url'];
    
    // If the photo_url doesn't start with 'uploads/', prepend the expected path
    if (!empty($photo_url) && strpos($photo_url, 'uploads/') !== 0) {
        $photo_url = "uploads/floors/{$property_id}/floor_{$floor_num}/" . basename($photo_url);
    }
    
    $floor_images[$floor_num][] = $photo_url;
}
$stmt_floor->close();

// Fetch property amenities (names for display)
$sql_amenities = "SELECT am.amenity_id, am.amenity_name FROM property_amenities pa
                  JOIN amenities am ON pa.amenity_id = am.amenity_id
                  WHERE pa.property_id = ? ORDER BY am.amenity_name ASC";
$stmt_amenities = $conn->prepare($sql_amenities);
$stmt_amenities->bind_param("i", $property_id);
$stmt_amenities->execute();
$result_amenities = $stmt_amenities->get_result();
$property_amenity_ids = [];
while ($row = $result_amenities->fetch_assoc()) {
    $amenities[] = $row['amenity_name'];
    $property_amenity_ids[] = (int)$row['amenity_id'];
}
$stmt_amenities->close();

// Fetch ALL amenities for edit modal checkboxes
$all_amenities = [];
$all_amenities_result = $conn->query("SELECT amenity_id, amenity_name FROM amenities ORDER BY amenity_name ASC");
if ($all_amenities_result) {
    $all_amenities = $all_amenities_result->fetch_all(MYSQLI_ASSOC);
}

// Check for sale verification submissions
$sql_sale_verification = "SELECT status FROM sale_verifications WHERE property_id = ? ORDER BY submitted_at DESC LIMIT 1";
$stmt_sale = $conn->prepare($sql_sale_verification);
$stmt_sale->bind_param("i", $property_id);
$stmt_sale->execute();
$result_sale = $stmt_sale->get_result();
$sale_verification = $result_sale->fetch_assoc();
$stmt_sale->close();
$sale_status = $sale_verification ? $sale_verification['status'] : null;

$is_property_sold = ($property_data['Status'] === 'Sold');

// Calculate metrics
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
    <title><?php echo htmlspecialchars($property_data['StreetAddress']); ?> - Property Details | HomeEstate Realty</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/agent_property_modals.css">
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

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            line-height: 1.6;
            color: var(--white);
            overflow-x: hidden;
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(26, 26, 26, 0.4); }
        ::-webkit-scrollbar-thumb { background: rgba(212, 175, 55, 0.4); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(212, 175, 55, 0.6); }

        /* Breadcrumb */
        .breadcrumb-section {
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.95) 0%, rgba(15, 15, 15, 0.98) 100%);
            padding: 16px 0;
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
        }
        .breadcrumb { background: transparent; margin: 0; padding: 0; font-size: 0.875rem; }
        .breadcrumb-item { color: var(--gray-400); }
        .breadcrumb-item a { color: var(--blue-light); text-decoration: none; transition: color 0.2s; }
        .breadcrumb-item a:hover { color: var(--blue); }
        .breadcrumb-item.active { color: var(--gray-300); }
        .breadcrumb-item + .breadcrumb-item::before { color: var(--gray-600); }

        /* Flash Alerts */
        .flash-alert {
            border: none; border-radius: 4px; font-weight: 500; padding: 14px 20px;
            display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
        }
        .flash-alert.alert-success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(34, 197, 94, 0.08));
            color: #4ade80; border-left: 3px solid #22c55e;
        }
        .flash-alert.alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.08));
            color: #ff6b6b; border-left: 3px solid #ef4444;
        }

        /* Status Badges */
        .agent-status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; font-size: 0.8rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; border-radius: 2px;
        }
        .badge-live { background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(34, 197, 94, 0.08)); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
        .badge-pending { background: linear-gradient(135deg, rgba(251, 191, 36, 0.15), rgba(251, 191, 36, 0.08)); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.3); }
        .badge-rejected { background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.08)); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-sale-pending { background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(59, 130, 246, 0.08)); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-sale-approved { background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(34, 197, 94, 0.08)); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
        .badge-sale-rejected { background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.08)); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }

        /* Hero Image Gallery - Agent Property View */
        .agent-prop-hero { padding: 40px 0 0; }

        .agent-prop-gallery-grid {
            display: grid; grid-template-columns: 2fr 1fr; gap: 12px;
            height: 550px; border-radius: 4px; overflow: hidden; position: relative;
        }
        .agent-prop-gallery-main {
            position: relative; overflow: hidden; background: var(--black); cursor: pointer;
        }
        .agent-prop-gallery-main img {
            width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;
            display: block;
        }
        .agent-prop-gallery-main:hover img { transform: scale(1.03); }

        .agent-prop-gallery-sidebar { display: grid; grid-template-rows: repeat(2, 1fr); gap: 12px; }

        .agent-prop-gallery-item {
            position: relative; overflow: hidden; background: var(--black); cursor: pointer;
        }
        .agent-prop-gallery-item img {
            width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;
            display: block;
        }
        .agent-prop-gallery-item:hover img { transform: scale(1.05); }

        .agent-prop-more-overlay {
            position: absolute; inset: 0; background: rgba(0, 0, 0, 0.58);
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; font-weight: 700; color: var(--white); pointer-events: none;
        }

        .agent-prop-gallery-placeholder {
            width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
            background: var(--black-lighter);
        }
        .agent-prop-gallery-placeholder i {
            font-size: 3rem; color: var(--gray-600);
        }

        /* Floor Navigation Pills */
        .agent-prop-floor-pills {
            position: absolute; top: 20px; left: 20px; z-index: 10;
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .agent-prop-floor-pill {
            padding: 10px 18px; background: rgba(10, 10, 10, 0.9); backdrop-filter: blur(8px);
            color: var(--white); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 2px;
            font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease;
            display: flex; align-items: center; gap: 6px;
        }
        .agent-prop-floor-pill:hover { background: rgba(37, 99, 235, 0.9); border-color: var(--blue); }
        .agent-prop-floor-pill.active {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            border-color: var(--gold); color: var(--black);
        }

        /* Property Header */
        .property-header { margin: 40px 0 0; }

        .property-status-badge {
            display: inline-block; padding: 8px 16px; font-size: 0.875rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1px; border-radius: 2px; margin-bottom: 16px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
        }
        .property-status-badge.for-rent {
            background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 50%, var(--blue-dark) 100%);
            color: var(--white);
        }
        .property-status-badge.sold {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 50%, #dc2626 100%);
            color: var(--white);
        }

        .property-title {
            font-size: 2.5rem; font-weight: 800; color: var(--white); margin-bottom: 12px; line-height: 1.2;
        }
        .property-address {
            font-size: 1.125rem; color: var(--gray-400); margin-bottom: 20px;
            display: flex; align-items: center; gap: 8px;
        }
        .property-price {
            font-size: 3rem; font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; filter: drop-shadow(0 0 12px rgba(212, 175, 55, 0.3));
        }
        .property-meta {
            display: flex; gap: 32px; flex-wrap: wrap; align-items: center;
        }
        .meta-item {
            display: flex; align-items: center; gap: 8px; font-size: 0.9375rem; color: var(--gray-300);
        }
        .meta-item i { color: var(--blue-light); font-size: 1.125rem; }

        .property-stats-bar {
            display: flex; gap: 24px; padding: 20px 0;
            border-top: 1px solid rgba(37, 99, 235, 0.15);
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            margin: 20px 0;
        }
        .stat-chip { display: flex; align-items: center; gap: 8px; }
        .stat-chip i { color: var(--blue-light); }
        .stat-chip.likes i { color: #ef4444; }
        .stat-chip .stat-val { font-weight: 600; color: var(--white); }

        /* Content Grid Layout */
        .property-content {
            display: grid; grid-template-columns: 1fr 400px; gap: 40px; margin-top: 40px; padding-bottom: 80px;
        }

        /* Content Cards */
        .content-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            border: 1px solid rgba(37, 99, 235, 0.15); border-radius: 4px;
            padding: 32px; margin-bottom: 24px; position: relative;
        }
        .content-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent); opacity: 0.5;
        }
        .content-card.gold-accent::before {
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
        }

        .section-title {
            font-size: 1.5rem; font-weight: 700; color: var(--white); margin-bottom: 20px;
            display: flex; align-items: center; gap: 12px;
        }
        .section-title i { color: var(--gold); font-size: 1.25rem; }

        .description-text { font-size: 1rem; line-height: 1.8; color: var(--gray-300); }

        /* Features Grid */
        .features-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px;
        }
        .feature-box {
            background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 2px; padding: 20px; text-align: center; transition: all 0.2s ease;
        }
        .feature-box:hover { border-color: rgba(37, 99, 235, 0.3); box-shadow: 0 4px 16px rgba(37, 99, 235, 0.15); }
        .feature-icon {
            width: 48px; height: 48px; background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            border-radius: 2px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px;
        }
        .feature-icon i { font-size: 24px; color: var(--black); }
        .feature-label { font-size: 0.75rem; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .feature-value { font-size: 1.25rem; font-weight: 700; color: var(--white); }

        /* Property Info Grid */
        .info-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;
        }
        .info-item-label { font-size: 0.875rem; color: var(--gray-400); margin-bottom: 4px; }
        .info-item-value { font-size: 0.9375rem; color: var(--white); font-weight: 600; }

        /* Amenities */
        .amenities-list {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;
        }
        .amenity-chip {
            display: flex; align-items: center; gap: 10px; padding: 10px;
            background: rgba(212, 175, 55, 0.05); border-radius: 2px;
            font-size: 0.875rem; color: var(--gray-300);
        }
        .amenity-chip i { color: var(--gold); font-size: 1rem; }

        /* Sidebar */
        .sticky-sidebar { position: sticky; top: 80px; align-self: start; }

        /* Metrics Grid */
        .metrics-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .metric-box {
            background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px; padding: 16px; text-align: center;
        }
        .metric-box .metric-label {
            font-size: 0.75rem; color: var(--gray-400); text-transform: uppercase;
            letter-spacing: 0.5px; margin-bottom: 6px; display: flex; align-items: center;
            justify-content: center; gap: 4px;
        }
        .metric-box .metric-value { font-size: 1.5rem; font-weight: 700; color: var(--white); }

        /* Price Timeline */
        .price-timeline { position: relative; padding-left: 1.5rem; }
        .timeline-item { position: relative; padding-bottom: 1.5rem; }
        .timeline-item:last-child { padding-bottom: 0; }
        .timeline-item::before {
            content: ''; position: absolute; left: -1.25rem; top: 0.5rem; bottom: -0.5rem;
            width: 2px; background: linear-gradient(180deg, var(--gold), transparent);
        }
        .timeline-item:last-child::before { display: none; }
        .timeline-marker {
            position: absolute; left: -1.5rem; top: 0.35rem; width: 10px; height: 10px;
            border-radius: 50%; background: var(--gold); z-index: 2;
        }
        .timeline-content {
            background: rgba(212, 175, 55, 0.05); padding: 12px 16px;
            border-radius: 4px; border-left: 3px solid var(--gold); transition: all 0.2s;
        }
        .timeline-content:hover { background: rgba(212, 175, 55, 0.1); transform: translateX(4px); }
        .timeline-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .timeline-date { font-size: 0.8rem; color: var(--gray-400); }
        .timeline-event {
            font-size: 0.7rem; padding: 2px 8px; background: var(--gold); color: var(--black);
            border-radius: 10px; font-weight: 600;
        }
        .timeline-price-val { font-size: 1.25rem; font-weight: 700; color: var(--white); display: flex; align-items: center; gap: 8px; }
        .price-change-badge {
            font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 10px;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .price-change-badge.text-success { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
        .price-change-badge.text-danger { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        
        /* Price History Expand/Collapse Animation */
        .price-history-extra {
            transition: all 0.3s ease-out;
            overflow: hidden;
        }
        
        /* Price History See More Button */
        #priceHistorySeeMoreBtn:hover {
            background: rgba(212, 175, 55, 0.12) !important;
            border-color: rgba(212, 175, 55, 0.5) !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.15);
        }

        /* Quick Action Buttons */
        .action-list { display: flex; flex-direction: column; gap: 10px; }
        .quick-action {
            display: flex; align-items: center; gap: 12px; padding: 14px 18px;
            background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px; color: var(--gray-300); font-weight: 600; font-size: 0.9rem;
            cursor: pointer; transition: all 0.2s; text-decoration: none;
        }
        .quick-action:hover {
            background: rgba(37, 99, 235, 0.12); border-color: rgba(37, 99, 235, 0.3);
            color: var(--white); transform: translateX(4px);
        }
        .quick-action i { color: var(--blue-light); font-size: 1.1rem; width: 20px; text-align: center; }
        .quick-action.gold-action { border-color: rgba(212, 175, 55, 0.15); background: rgba(212, 175, 55, 0.05); }
        .quick-action.gold-action:hover { background: rgba(212, 175, 55, 0.12); border-color: rgba(212, 175, 55, 0.3); }
        .quick-action.gold-action i { color: var(--gold); }
        .quick-action.success-action { border-color: rgba(34, 197, 94, 0.15); background: rgba(34, 197, 94, 0.05); }
        .quick-action.success-action:hover { background: rgba(34, 197, 94, 0.12); border-color: rgba(34, 197, 94, 0.3); }
        .quick-action.success-action i { color: #4ade80; }

        .locked-notice {
            background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 4px; padding: 16px; color: var(--gray-300); font-size: 0.875rem;
            display: flex; align-items: flex-start; gap: 10px;
        }
        .locked-notice i { color: #ef4444; font-size: 1.1rem; margin-top: 2px; }

        /* Lightbox */
        .lightbox-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.96); z-index: 10000;
            align-items: center; justify-content: center;
        }
        .lightbox-overlay.active { display: flex; }
        .lightbox-content { position: relative; max-width: 90%; max-height: 90%; display: flex; align-items: center; justify-content: center; }
        .lightbox-image { max-width: 100%; max-height: 90vh; object-fit: contain; }
        .lightbox-close {
            position: absolute; top: 20px; right: 20px; width: 48px; height: 48px;
            background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%; color: white; font-size: 24px; cursor: pointer;
            transition: all 0.2s; display: flex; align-items: center; justify-content: center; z-index: 10001;
        }
        .lightbox-close:hover { background: rgba(255, 255, 255, 0.2); }
        .lightbox-prev, .lightbox-next {
            position: absolute; top: 50%; transform: translateY(-50%); width: 56px; height: 56px;
            background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%; color: white; font-size: 24px; cursor: pointer;
            transition: all 0.2s; display: flex; align-items: center; justify-content: center; z-index: 10001;
        }
        .lightbox-prev { left: 40px; }
        .lightbox-next { right: 40px; }
        .lightbox-prev:hover, .lightbox-next:hover { background: rgba(255, 255, 255, 0.2); }
        .lightbox-counter {
            position: absolute; bottom: 40px; left: 50%; transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.7); color: white; padding: 10px 20px;
            border-radius: 20px; font-size: 0.875rem; z-index: 10001;
        }
        .lightbox-label {
            position: absolute; top: 20px; left: 20px; background: rgba(0,0,0,0.7);
            color: var(--gold); padding: 8px 16px; border-radius: 4px; font-size: 0.875rem;
            font-weight: 600; z-index: 10001;
        }

        /* Rental Details */
        .rental-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .rental-item { 
            background: rgba(37, 99, 235, 0.06); border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 4px; padding: 16px; 
        }
        .rental-item .rental-label { font-size: 0.75rem; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .rental-item .rental-value { font-size: 1.1rem; font-weight: 700; color: var(--white); }

        /* Responsive */
        @media (max-width: 1024px) {
            .property-content { grid-template-columns: 1fr; }
            .sticky-sidebar { position: static; }
        }
        @media (max-width: 768px) {
            .agent-prop-gallery-grid { grid-template-columns: 1fr; height: auto; }
            .agent-prop-gallery-main { height: 350px; }
            .agent-prop-gallery-sidebar { grid-template-columns: repeat(2, 1fr); grid-template-rows: auto; }
            .agent-prop-gallery-item { height: 180px; }
            .agent-prop-floor-pills { flex-wrap: wrap; max-width: calc(100% - 40px); }
            .agent-prop-floor-pill { padding: 8px 14px; font-size: 0.8rem; }
            .property-title { font-size: 1.75rem; }
            .property-price { font-size: 2rem; }
            .features-grid { grid-template-columns: repeat(2, 1fr); }
            .lightbox-prev { left: 10px; }
            .lightbox-next { right: 10px; }
            .info-grid { grid-template-columns: 1fr; }
        }

        /* Optional field indicator */
        .text-optional {
            color: #f59e0b;
            font-weight: 500;
            font-size: 0.8em;
        }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Dark Agent Portal Theme
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

        .sk-breadcrumb {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.9rem 1.5rem;
            background: rgba(26,26,26,0.6);
            border-bottom: 1px solid rgba(37,99,235,0.1);
        }

        .sk-hero-gallery {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            padding: 40px 20px 0;
            max-width: 1320px;
            margin: 0 auto;
        }
        .sk-hero-main { height: 420px; border-radius: 4px; }
        .sk-hero-sidebar { display: grid; grid-template-rows: repeat(2, 1fr); gap: 12px; }
        .sk-hero-thumb { border-radius: 4px; }

        .sk-prop-header {
            padding: 2.5rem 0 1.5rem;
            border-bottom: 1px solid rgba(37,99,235,0.1);
            margin-bottom: 2rem;
        }

        .sk-detail-grid {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 2rem;
            padding-bottom: 3rem;
        }

        .sk-content-card {
            background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
            border: 1px solid rgba(37,99,235,0.15);
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }

        .sk-feat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        .sk-feat-box { height: 80px; border-radius: 4px; }

        .sk-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem 2rem;
        }

        .sk-metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .sk-line { display: block; border-radius: 4px; }

        @media (max-width: 1024px) {
            .sk-detail-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sk-hero-gallery { grid-template-columns: 1fr; }
            .sk-hero-main { height: 300px; }
            .sk-hero-sidebar { grid-template-columns: repeat(2, 1fr); grid-template-rows: auto; }
            .sk-hero-thumb { height: 150px; }
            .sk-feat-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* ===== TOAST NOTIFICATIONS — Agent Portal (Dark Theme) ===== */
        #toastContainer {
            position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999;
            display: flex; flex-direction: column; gap: 0.6rem; pointer-events: none;
        }
        .app-toast {
            display: flex; align-items: flex-start; gap: 0.85rem;
            background: linear-gradient(135deg, rgba(26,26,26,0.97) 0%, rgba(10,10,10,0.98) 100%);
            border: 1px solid rgba(37,99,235,0.15); border-radius: 12px;
            padding: 0.9rem 1.1rem; min-width: 300px; max-width: 400px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.04);
            pointer-events: all; position: relative; overflow: hidden;
            animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;
            backdrop-filter: blur(12px);
        }
        @keyframes toast-in  { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }
        .app-toast::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.15); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.12);  color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.12);  color: #3b82f6; }
        .app-toast-body     { flex:1; min-width:0; }
        .app-toast-title    { font-size:0.82rem; font-weight:700; color:#f1f5f9; margin-bottom:0.2rem; }
        .app-toast-msg      { font-size:0.78rem; color:#9ca4ab; line-height:1.4; word-break:break-word; }
        .app-toast-close    { background:none; border:none; cursor:pointer; color:#5d6d7d; font-size:0.8rem; padding:0; line-height:1; flex-shrink:0; transition:color .2s; }
        .app-toast-close:hover { color:#f1f5f9; }
        .app-toast-progress { position:absolute; bottom:0; left:0; height:2px; border-radius:0 0 0 12px; }
        .toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        @keyframes toast-progress { from { width:100%; } to { width:0%; } }
    </style>
</head>
<body>
    <?php include 'agent_navbar.php'; ?>

<noscript><style>
    #sk-screen    { display: none !important; }
    #page-content { display: block !important; opacity: 1 !important; }
</style></noscript>

<div id="sk-screen" role="presentation" aria-hidden="true">

    <!-- Skeleton: Breadcrumb bar -->
    <div class="sk-breadcrumb">
        <div class="sk-shimmer" style="width:260px;height:13px;border-radius:3px;"></div>
        <div style="display:flex;gap:0.5rem;">
            <div class="sk-shimmer" style="width:90px;height:28px;border-radius:4px;"></div>
            <div class="sk-shimmer" style="width:90px;height:28px;border-radius:4px;"></div>
        </div>
    </div>

    <!-- Skeleton: Hero image gallery -->
    <div class="sk-hero-gallery">
        <div class="sk-hero-main sk-shimmer"></div>
        <div class="sk-hero-sidebar">
            <div class="sk-hero-thumb sk-shimmer"></div>
            <div class="sk-hero-thumb sk-shimmer"></div>
        </div>
    </div>

    <!-- Skeleton: Property header + two-column detail -->
    <div class="container" style="padding:0 20px;">

        <div class="sk-prop-header">
            <div class="sk-line sk-shimmer" style="width:90px;height:22px;border-radius:3px;margin-bottom:0.75rem;"></div>
            <div class="sk-line sk-shimmer" style="width:55%;height:28px;margin-bottom:0.6rem;"></div>
            <div class="sk-line sk-shimmer" style="width:42%;height:14px;margin-bottom:1.25rem;"></div>
            <div class="sk-line sk-shimmer" style="width:28%;height:38px;margin-bottom:1rem;"></div>
            <div style="display:flex;gap:1.5rem;">
                <div class="sk-shimmer" style="width:80px;height:13px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:70px;height:13px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:130px;height:13px;border-radius:3px;"></div>
            </div>
        </div>

        <div class="sk-detail-grid">

            <!-- Main column: 3 content cards -->
            <div>
                <div class="sk-content-card" style="padding:1.5rem;">
                    <div class="sk-line sk-shimmer" style="width:160px;height:17px;margin-bottom:1.25rem;"></div>
                    <div class="sk-feat-grid">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="sk-feat-box sk-shimmer"></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="sk-content-card" style="padding:1.5rem;">
                    <div class="sk-line sk-shimmer" style="width:170px;height:17px;margin-bottom:1rem;"></div>
                    <div class="sk-line sk-shimmer" style="width:100%;height:12px;margin-bottom:6px;"></div>
                    <div class="sk-line sk-shimmer" style="width:96%;height:12px;margin-bottom:6px;"></div>
                    <div class="sk-line sk-shimmer" style="width:88%;height:12px;margin-bottom:6px;"></div>
                    <div class="sk-line sk-shimmer" style="width:70%;height:12px;"></div>
                </div>
                <div class="sk-content-card" style="padding:1.5rem;">
                    <div class="sk-line sk-shimmer" style="width:180px;height:17px;margin-bottom:1.25rem;"></div>
                    <div class="sk-info-grid">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                        <div>
                            <div class="sk-line sk-shimmer" style="width:75%;height:11px;margin-bottom:6px;"></div>
                            <div class="sk-line sk-shimmer" style="width:55%;height:15px;"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar column: metrics + price timeline -->
            <aside>
                <div class="sk-content-card" style="padding:1.5rem;margin-bottom:1.5rem;">
                    <div class="sk-line sk-shimmer" style="width:130px;height:15px;margin-bottom:1rem;"></div>
                    <div class="sk-metrics-grid">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                        <div style="background:rgba(26,26,26,0.5);border:1px solid rgba(37,99,235,0.1);border-radius:4px;padding:0.9rem;">
                            <div class="sk-line sk-shimmer" style="width:70%;height:11px;margin-bottom:8px;"></div>
                            <div class="sk-line sk-shimmer" style="width:50%;height:22px;"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="sk-content-card" style="padding:1.5rem;">
                    <div class="sk-line sk-shimmer" style="width:120px;height:15px;margin-bottom:1.25rem;"></div>
                    <?php for ($i = 0; $i < 3; $i++): ?>
                    <div style="display:flex;gap:0.75rem;padding-bottom:1rem;">
                        <div class="sk-shimmer" style="width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px;"></div>
                        <div style="flex:1;">
                            <div class="sk-line sk-shimmer" style="width:80%;height:13px;margin-bottom:5px;"></div>
                            <div class="sk-line sk-shimmer" style="width:55%;height:11px;"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </aside>
        </div>
    </div>

</div><!-- /#sk-screen -->

<div id="page-content">
    <!-- Breadcrumb -->
    <div class="breadcrumb-section">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="agent_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="agent_property.php">My Properties</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Property Details</li>
                    </ol>
                </nav>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($property_data['approval_status'] === 'approved'): ?>
                        <span class="agent-status-badge badge-live"><i class="bi bi-check-circle-fill"></i> Live Listing</span>
                    <?php elseif ($property_data['approval_status'] === 'pending'): ?>
                        <span class="agent-status-badge badge-pending"><i class="bi bi-clock-fill"></i> Pending Review</span>
                    <?php else: ?>
                        <span class="agent-status-badge badge-rejected"><i class="bi bi-x-circle-fill"></i> Rejected</span>
                    <?php endif; ?>

                    <?php if ($sale_status === 'Pending'): ?>
                        <span class="agent-status-badge badge-sale-pending"><i class="bi bi-hourglass-split"></i> Sale Verification Pending</span>
                    <?php elseif ($sale_status === 'Approved'): ?>
                        <span class="agent-status-badge badge-sale-approved"><i class="bi bi-patch-check-fill"></i> Sale Verified</span>
                    <?php elseif ($sale_status === 'Rejected'): ?>
                        <span class="agent-status-badge badge-sale-rejected"><i class="bi bi-x-circle-fill"></i> Sale Verification Rejected</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash messages shown as toasts via skeleton:hydrated -->

    <!-- Property Hero / Image Gallery -->
    <section class="agent-prop-hero">
        <div class="container">
            <div class="agent-prop-gallery-grid">
                <div class="agent-prop-gallery-main" onclick="openLightbox(0)">
                    <img src="../<?php echo htmlspecialchars($property_images[0] ?? 'images/placeholder.jpg'); ?>" alt="Main property image" id="agentPropMainImage">
                    <!-- Floor Navigation Pills -->
                    <div class="agent-prop-floor-pills">
                        <button class="agent-prop-floor-pill active" data-type="featured" onclick="event.stopPropagation(); switchHeroView('featured')">
                            <i class="bi bi-star-fill"></i> Featured
                        </button>
                        <?php if (!empty($floor_images)): ?>
                            <?php foreach ($floor_images as $floor_num => $images): ?>
                                <button class="agent-prop-floor-pill" data-type="floor" data-floor="<?php echo $floor_num; ?>" onclick="event.stopPropagation(); switchHeroView('floor', <?php echo $floor_num; ?>)">
                                    <i class="bi bi-building"></i> Floor <?php echo $floor_num; ?>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="agent-prop-gallery-sidebar">
                    <div class="agent-prop-gallery-item" id="agentPropSidebarItem0" <?php if(count($property_images) >= 2): ?>onclick="openLightbox(1)"<?php else: ?>style="cursor:default;"<?php endif; ?>>
                        <?php if(count($property_images) >= 2): ?>
                            <img src="../<?php echo htmlspecialchars($property_images[1]); ?>" alt="Property image 2" id="agentPropSideImg0">
                        <?php else: ?>
                            <div class="agent-prop-gallery-placeholder">
                                <i class="bi bi-image"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="agent-prop-gallery-item" id="agentPropSidebarItem1" <?php if(count($property_images) >= 3): ?>onclick="openLightbox(2)"<?php else: ?>style="cursor:default;"<?php endif; ?>>
                        <?php if(count($property_images) >= 3): ?>
                            <img src="../<?php echo htmlspecialchars($property_images[2]); ?>" alt="Property image 3" id="agentPropSideImg1">
                        <?php else: ?>
                            <div class="agent-prop-gallery-placeholder">
                                <i class="bi bi-image"></i>
                            </div>
                        <?php endif; ?>
                        <?php if(count($property_images) > 3): ?>
                            <div class="agent-prop-more-overlay" id="agentPropMoreOverlay">+<?php echo count($property_images) - 3; ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Property Content -->
    <section class="container" style="padding: 0 20px;">
        <!-- Property Header -->
        <div class="property-header">
            <span class="property-status-badge <?php echo $property_data['Status'] === 'For Rent' ? 'for-rent' : ($property_data['Status'] === 'Sold' ? 'sold' : ''); ?>">
                <?php echo htmlspecialchars($property_data['Status']); ?>
            </span>
            <h1 class="property-title"><?php echo htmlspecialchars($property_data['StreetAddress']); ?></h1>
            <div class="property-address">
                <i class="bi bi-geo-alt-fill"></i>
                <?php echo htmlspecialchars($property_data['City'] . ', ' . $property_data['Province'] . ' ' . $property_data['ZIP']); ?>
                <?php if (!empty($property_data['Barangay'])): ?>
                    &mdash; <?php echo htmlspecialchars($property_data['Barangay']); ?>
                <?php endif; ?>
            </div>
            <div class="property-meta">
                <div class="property-price">₱<?php echo number_format($property_data['ListingPrice']); ?></div>
                <div class="property-stats-bar">
                    <div class="stat-chip">
                        <i class="bi bi-eye-fill"></i>
                        <span class="stat-val"><?php echo number_format($property_data['ViewsCount']); ?></span>
                        <span style="color: var(--gray-400);">views</span>
                    </div>
                    <div class="stat-chip likes">
                        <i class="bi bi-heart-fill"></i>
                        <span class="stat-val"><?php echo number_format($property_data['Likes']); ?></span>
                        <span style="color: var(--gray-400);">likes</span>
                    </div>
                    <div class="stat-chip">
                        <i class="bi bi-calendar3"></i>
                        <span style="color: var(--gray-400);">Listed</span>
                        <span class="stat-val"><?php echo date('M d, Y', strtotime($property_data['ListingDate'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="property-content">
            <!-- Main Content -->
            <div>
                <!-- Key Features -->
                <div class="content-card">
                    <h2 class="section-title"><i class="bi bi-house-door-fill"></i> Property Features</h2>
                    <div class="features-grid">
                        <div class="feature-box">
                            <div class="feature-icon"><i class="bi bi-door-open-fill"></i></div>
                            <div class="feature-label">Bedrooms</div>
                            <div class="feature-value"><?php echo $property_data['Bedrooms'] ?? 'N/A'; ?></div>
                        </div>
                        <div class="feature-box">
                            <div class="feature-icon"><i class="bi bi-droplet-fill"></i></div>
                            <div class="feature-label">Bathrooms</div>
                            <div class="feature-value"><?php echo $property_data['Bathrooms'] ?? 'N/A'; ?></div>
                        </div>
                        <div class="feature-box">
                            <div class="feature-icon"><i class="bi bi-arrows-fullscreen"></i></div>
                            <div class="feature-label">Area</div>
                            <div class="feature-value"><?php echo number_format($property_data['SquareFootage']); ?> ft²</div>
                        </div>
                        <?php if (!empty($property_data['LotSize'])): ?>
                        <div class="feature-box">
                            <div class="feature-icon"><i class="bi bi-map"></i></div>
                            <div class="feature-label">Lot Size</div>
                            <div class="feature-value"><?php echo number_format($property_data['LotSize'], 2); ?> ac</div>
                        </div>
                        <?php endif; ?>
                        <div class="feature-box">
                            <div class="feature-icon"><i class="bi bi-calendar-check"></i></div>
                            <div class="feature-label">Year Built</div>
                            <div class="feature-value"><?php echo $property_data['YearBuilt'] ?? 'N/A'; ?></div>
                        </div>
                        <div class="feature-box">
                            <div class="feature-icon"><i class="bi bi-car-front-fill"></i></div>
                            <div class="feature-label">Parking</div>
                            <div class="feature-value" style="font-size: 0.9rem;"><?php echo htmlspecialchars($property_data['ParkingType'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div class="content-card">
                    <h2 class="section-title"><i class="bi bi-card-text"></i> Property Description</h2>
                    <p class="description-text"><?php echo nl2br(htmlspecialchars($property_data['ListingDescription'] ?? 'No description provided.')); ?></p>
                </div>

                <!-- Property Information -->
                <div class="content-card">
                    <h2 class="section-title"><i class="bi bi-info-circle-fill"></i> Property Information</h2>
                    <div class="info-grid">
                        <div>
                            <div class="info-item-label">Property Type</div>
                            <div class="info-item-value"><?php echo htmlspecialchars($property_data['PropertyType']); ?></div>
                        </div>
                        <div>
                            <div class="info-item-label">Barangay</div>
                            <div class="info-item-value"><?php echo htmlspecialchars($property_data['Barangay'] ?? 'Not specified'); ?></div>
                        </div>
                        <div>
                            <div class="info-item-label">Province</div>
                            <div class="info-item-value"><?php echo htmlspecialchars($property_data['Province']); ?></div>
                        </div>
                        <div>
                            <div class="info-item-label">MLS Number</div>
                            <div class="info-item-value"><?php echo htmlspecialchars($property_data['MLSNumber']); ?></div>
                        </div>
                        <div>
                            <div class="info-item-label">Price per Sq Ft</div>
                            <div class="info-item-value"><?php echo $price_per_sqft; ?></div>
                        </div>
                        <div>
                            <div class="info-item-label">Listed Date</div>
                            <div class="info-item-value"><?php echo date('M d, Y', strtotime($property_data['ListingDate'])); ?></div>
                        </div>
                        <div>
                            <div class="info-item-label">Source (MLS)</div>
                            <div class="info-item-value"><?php echo htmlspecialchars($property_data['Source']); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Rental Details (if applicable) -->
                <?php if ($rental_details): ?>
                <div class="content-card gold-accent">
                    <h2 class="section-title"><i class="bi bi-key-fill"></i> Rental Details</h2>
                    <div class="rental-grid">
                        <?php if (!empty($rental_details['monthly_rent'])): ?>
                        <div class="rental-item">
                            <div class="rental-label">Monthly Rent</div>
                            <div class="rental-value">₱<?php echo number_format($rental_details['monthly_rent']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($rental_details['security_deposit'])): ?>
                        <div class="rental-item">
                            <div class="rental-label">Security Deposit</div>
                            <div class="rental-value">₱<?php echo number_format($rental_details['security_deposit']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($rental_details['lease_term_months'])): ?>
                        <div class="rental-item">
                            <div class="rental-label">Lease Term</div>
                            <div class="rental-value"><?php echo $rental_details['lease_term_months']; ?> months</div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($rental_details['furnishing'])): ?>
                        <div class="rental-item">
                            <div class="rental-label">Furnishing</div>
                            <div class="rental-value"><?php echo htmlspecialchars($rental_details['furnishing']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($rental_details['available_from'])): ?>
                        <div class="rental-item">
                            <div class="rental-label">Available From</div>
                            <div class="rental-value"><?php echo date('M d, Y', strtotime($rental_details['available_from'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Amenities -->
                <?php if (!empty($amenities)): ?>
                <div class="content-card">
                    <h2 class="section-title"><i class="bi bi-stars"></i> Amenities & Features</h2>
                    <div class="amenities-list">
                        <?php foreach ($amenities as $amenity): ?>
                            <div class="amenity-chip">
                                <i class="bi bi-check-circle-fill"></i>
                                <span><?php echo htmlspecialchars($amenity); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <aside class="sticky-sidebar">
                <!-- Performance Metrics -->
                <div class="content-card gold-accent">
                    <h2 class="section-title" style="font-size: 1.2rem;"><i class="bi bi-graph-up-arrow"></i> Performance Metrics</h2>
                    <div class="metrics-grid">
                        <div class="metric-box">
                            <div class="metric-label"><i class="bi bi-calendar-day"></i> Days on Market</div>
                            <div class="metric-value"><?php echo $days_on_market; ?></div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-label"><i class="bi bi-eye"></i> Total Views</div>
                            <div class="metric-value"><?php echo number_format($property_data['ViewsCount']); ?></div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-label"><i class="bi bi-heart"></i> Likes</div>
                            <div class="metric-value"><?php echo number_format($property_data['Likes']); ?></div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-label"><i class="bi bi-rulers"></i> Price/Sq Ft</div>
                            <div class="metric-value" style="font-size: 1.1rem;"><?php echo $price_per_sqft; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Price History -->
                <div class="content-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="section-title mb-0" style="font-size: 1.2rem;"><i class="bi bi-clock-history"></i> Price History</h2>
                        <?php if (!$is_property_sold && $property_data['approval_status'] === 'approved'): ?>
                        <button type="button" class="btn btn-sm btn-gold" data-bs-toggle="modal" data-bs-target="#updatePriceModal" style="padding: 6px 14px; font-size: 0.8rem;">
                            <i class="bi bi-pencil-square"></i> Update
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($price_history)): ?>
                        <div style="text-align: center; padding: 20px 0;">
                            <i class="bi bi-graph-down" style="font-size: 2rem; color: var(--gray-600); opacity: 0.5;"></i>
                            <p style="color: var(--gray-500); margin-top: 8px; font-size: 0.875rem;">No price changes recorded</p>
                        </div>
                    <?php else: ?>
                        <div class="price-timeline">
                            <?php 
                            $total_count = count($price_history);
                            $initial_display = 3;
                            foreach ($price_history as $index => $history): 
                                $is_hidden = ($index >= $initial_display && $total_count > $initial_display);
                            ?>
                                <div class="timeline-item <?php echo $is_hidden ? 'price-history-extra' : ''; ?>" <?php echo $is_hidden ? 'style="display: none; opacity: 0; max-height: 0;"' : ''; ?>>
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-header">
                                            <span class="timeline-date"><?php echo $history['event_date']; ?></span>
                                            <span class="timeline-event"><?php echo $history['event_type']; ?></span>
                                        </div>
                                        <div class="timeline-price-val">
                                            <?php echo $history['price']; ?>
                                            <?php if ($history['change_percentage'] !== null): ?>
                                                <span class="price-change-badge <?php echo $history['change_class']; ?>">
                                                    <i class="bi bi-<?php echo $history['change_percentage'] > 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                                    <?php 
                                                        $display_percentage = abs($history['change_percentage']);
                                                        echo number_format($display_percentage, 2);
                                                    ?>%
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($total_count > $initial_display): ?>
                            <div style="text-align: center; margin-top: 16px;">
                                <button type="button" id="priceHistorySeeMoreBtn" class="btn btn-sm" style="padding: 8px 20px; font-size: 0.875rem; border: 1px solid rgba(212, 175, 55, 0.3); background: rgba(212, 175, 55, 0.05); color: var(--gold); transition: all 0.2s ease;">
                                    <i class="bi bi-chevron-down"></i> See More (<?php echo $total_count - $initial_display; ?> older)
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="content-card">
                    <h2 class="section-title" style="font-size: 1.2rem;"><i class="bi bi-lightning-charge-fill"></i> Quick Actions</h2>
                    <div class="action-list">
                        <?php if (!$is_property_sold): ?>
                            <button type="button" class="quick-action" data-bs-toggle="modal" data-bs-target="#editPropertyModal">
                                <i class="bi bi-pencil-square"></i> Edit Property Details
                            </button>
                            <?php if ($property_data['approval_status'] === 'approved'): ?>
                            <button type="button" class="quick-action gold-action" data-bs-toggle="modal" data-bs-target="#updatePriceModal">
                                <i class="bi bi-currency-exchange"></i> Update Listing Price
                            </button>
                            <?php endif; ?>
                            <?php if ($property_data['approval_status'] === 'rejected'): ?>
                            <div class="locked-notice" style="background: rgba(251, 191, 36, 0.08); border-color: rgba(251, 191, 36, 0.2);">
                                <i class="bi bi-info-circle-fill" style="color: #fbbf24;"></i>
                                <div><strong>Rejected</strong><br>This property was rejected by admin. You can edit and resubmit it for review.</div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($is_property_sold): ?>
                            <div class="locked-notice">
                                <i class="bi bi-lock-fill"></i>
                                <div><strong>Property Sold</strong><br>This property is locked for editing to maintain historical accuracy.</div>
                            </div>
                        <?php endif; ?>

                        <button type="button" class="quick-action" data-bs-toggle="modal" data-bs-target="#tourRequestsModal">
                            <i class="bi bi-calendar-check"></i> View Tour Requests
                        </button>

                        <?php if ($property_data['approval_status'] === 'approved' && !$sale_status): ?>
                            <button type="button" class="quick-action success-action" data-bs-toggle="modal" data-bs-target="#markSoldModal"
                                    data-property-id="<?php echo $property_id; ?>"
                                    data-property-title="<?php echo htmlspecialchars($property_data['StreetAddress']); ?>">
                                <i class="bi bi-check-circle"></i> Mark as Sold
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</div><!-- /#page-content -->

    <!-- Image Lightbox -->
    <div class="lightbox-overlay" id="lightbox" onclick="closeLightbox(event)">
        <button class="lightbox-close" onclick="closeLightbox(event)"><i class="bi bi-x-lg"></i></button>
        <button class="lightbox-prev" onclick="changeImage(-1, event)"><i class="bi bi-chevron-left"></i></button>
        <div class="lightbox-content">
            <img src="" alt="Property image" class="lightbox-image" id="lightboxImage">
        </div>
        <button class="lightbox-next" onclick="changeImage(1, event)"><i class="bi bi-chevron-right"></i></button>
        <div class="lightbox-label" id="lightboxLabel">Featured Photos</div>
        <div class="lightbox-counter" id="lightboxCounter"></div>
    </div>

    <!-- Edit/Update Property Modal (external include) -->
    <?php include 'modals/edit_property_modal.php'; ?>

    <!-- Mark as Sold Modal -->
    <div class="modal fade modal-dark" id="markSoldModal" tabindex="-1" aria-labelledby="markSoldModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="markSoldModalLabel"><i class="bi bi-check-circle"></i> Mark Property as Sold</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form action="mark_as_sold_process.php" method="POST" enctype="multipart/form-data" id="markSoldForm">
                        <input type="hidden" name="property_id" id="propertyId" value="<?php echo $property_id; ?>">
                        <div class="mb-3">
                            <p style="color: var(--gray-400);">You are about to mark the following property as sold:</p>
                            <p style="font-weight: 700; color: var(--white);" id="propertyTitle"><?php echo htmlspecialchars($property_data['StreetAddress']); ?></p>
                        </div>
                        <div class="mb-3">
                            <label for="sale_price" class="form-label">Sale Price (₱)</label>
                            <input type="number" step="0.01" class="form-control" id="sale_price" name="sale_price" placeholder="Final sale price" required>
                        </div>
                        <div class="mb-3">
                            <label for="sale_date" class="form-label">Sale Date</label>
                            <input type="date" class="form-control" id="sale_date" name="sale_date" max="<?php echo date('Y-m-d'); ?>" required style="color-scheme: dark;">
                        </div>
                        <div class="mb-3">
                            <label for="buyer_name" class="form-label">Buyer's Name</label>
                            <input type="text" class="form-control" id="buyer_name" name="buyer_name" placeholder="Buyer's full name" required>
                        </div>
                        <div class="mb-3">
                            <label for="buyer_email" class="form-label">Buyer's Email</label>
                            <input type="email" class="form-control" id="buyer_email" name="buyer_email" placeholder="buyer@email.com">
                        </div>
                        <div class="mb-3">
                            <label for="sale_documents" class="form-label">Sale Documents</label>
                            <input type="file" class="form-control" id="sale_documents" name="sale_documents[]" multiple required>
                            <div class="form-text">Upload proof of sale (contract, deed, receipt). Multiple files allowed.</div>
                        </div>
                        <div class="mb-3">
                            <label for="additional_notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="additional_notes" name="additional_notes" rows="2" placeholder="Optional notes about the sale"></textarea>
                        </div>
                        <button type="submit" class="btn btn-gold w-100"><i class="bi bi-send-fill me-1"></i> Submit for Verification</button>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-dark-outline" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <?php include 'logout_agent_modal.php'; ?>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <!-- Update Price Modal -->
    <div class="modal fade modal-dark" id="updatePriceModal" tabindex="-1" aria-labelledby="updatePriceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updatePriceModalLabel"><i class="bi bi-currency-exchange"></i> Update Listing Price</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="priceUpdateAlert" class="flash-alert d-none" role="alert"></div>
                    <form id="updatePriceForm">
                        <input type="hidden" name="property_id" value="<?php echo (int)$property_id; ?>">
                        <div class="current-price-display">
                            <div class="label">Current Price</div>
                            <div class="price-amount">₱<?php echo number_format($property_data['ListingPrice'], 2); ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Listing Price (₱)</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="new_price" id="newPriceInput" placeholder="0.00" required>
                        </div>
                        <div id="priceChangePreview" style="display: none;"></div>
                        <div class="mb-3">
                            <label class="form-label">Reason for Price Change (Optional)</label>
                            <textarea class="form-control" name="reason" rows="2" placeholder="Market adjustment, renovation, etc."></textarea>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-dark-outline" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> Update Price</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Tour Requests Modal -->
    <div class="modal fade modal-dark" id="tourRequestsModal" tabindex="-1" aria-labelledby="tourRequestsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tourRequestsModalLabel"><i class="bi bi-calendar-check"></i> Tour Requests</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="tourRequestsContent">
                        <div class="text-center py-5">
                            <div class="spinner-border" role="status" style="color: var(--gold);">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-3" style="color: var(--gray-500);">Loading tour requests...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ── Global data passed from PHP to JS ──
    window._propertyId = <?php echo (int)$property_id; ?>;
    window._currentListingPrice = <?php echo (float)$property_data['ListingPrice']; ?>;
    window._floorImagesData = <?php echo json_encode($floor_images); ?>;

    // Image data from PHP
    const featuredImages = <?php echo json_encode($property_images); ?>;
    const floorImagesRaw = <?php echo json_encode($floor_images); ?>;
    
    // Convert floor images object to proper structure (PHP numeric keys create object, not array)
    const floorImages = {};
    if (floorImagesRaw && typeof floorImagesRaw === 'object') {
        Object.keys(floorImagesRaw).forEach(key => {
            floorImages[parseInt(key)] = floorImagesRaw[key];
        });
    }

    let currentImageIndex = 0;
    let currentImages = featuredImages;
    let currentHeroView = 'featured';
    let currentHeroFloor = null;

    // Update sidebar thumbnails for floor/featured switching
    function updateSidebar(images) {
        const total = images.length;
        const item0 = document.getElementById('agentPropSidebarItem0');
        const item1 = document.getElementById('agentPropSidebarItem1');

        if (item0) {
            if (total >= 2) {
                let img = item0.querySelector('img');
                if (img) { img.src = '../' + images[1]; img.alt = 'Property image 2'; }
                else { item0.innerHTML = '<img src="../' + images[1] + '" alt="Property image 2" id="agentPropSideImg0">'; }
                item0.style.cursor = 'pointer';
                item0.onclick = function() { openLightbox(1); };
            } else {
                item0.innerHTML = '<div class="agent-prop-gallery-placeholder"><i class="bi bi-image"></i></div>';
                item0.style.cursor = 'default'; item0.onclick = null;
            }
        }

        if (item1) {
            if (total >= 3) {
                let img = item1.querySelector('img');
                if (img) { img.src = '../' + images[2]; img.alt = 'Property image 3'; }
                else { item1.innerHTML = '<img src="../' + images[2] + '" alt="Property image 3" id="agentPropSideImg1">'; }
                item1.style.cursor = 'pointer';
                item1.onclick = function() { openLightbox(2); };
            } else {
                item1.innerHTML = '<div class="agent-prop-gallery-placeholder"><i class="bi bi-image"></i></div>';
                item1.style.cursor = 'default'; item1.onclick = null;
            }
            let overlay = item1.querySelector('.agent-prop-more-overlay');
            if (total > 3) {
                if (!overlay) { overlay = document.createElement('div'); overlay.className = 'agent-prop-more-overlay'; overlay.id = 'agentPropMoreOverlay'; item1.appendChild(overlay); }
                overlay.textContent = '+' + (total - 3); overlay.style.display = 'flex';
            } else if (overlay) { overlay.style.display = 'none'; }
        }
    }

    // Switch hero view between featured and floor images
    function switchHeroView(viewType, floorNum = null) {
        const mainImage = document.getElementById('agentPropMainImage');
        if (!mainImage) return;
        document.querySelectorAll('.agent-prop-floor-pill').forEach(pill => {
            if (viewType === 'featured' && pill.dataset.type === 'featured') pill.classList.add('active');
            else if (viewType === 'floor' && pill.dataset.type === 'floor' && parseInt(pill.dataset.floor) === floorNum) pill.classList.add('active');
            else pill.classList.remove('active');
        });
        currentHeroView = viewType; currentHeroFloor = floorNum;
        if (viewType === 'featured') { currentImages = featuredImages; }
        else if (viewType === 'floor') {
            const floorKey = parseInt(floorNum);
            if (floorImages[floorKey] && Array.isArray(floorImages[floorKey]) && floorImages[floorKey].length > 0) { currentImages = floorImages[floorKey]; }
            else { alert('No images available for Floor ' + floorKey); return; }
        }
        if (currentImages && currentImages.length > 0) {
            mainImage.src = '../' + currentImages[0];
            mainImage.alt = viewType === 'featured' ? 'Featured property image' : 'Floor ' + floorNum + ' image';
            updateSidebar(currentImages);
        }
    }

    // Lightbox
    function openLightbox(index) { currentImageIndex = index; updateLightboxImage(); document.getElementById('lightbox').classList.add('active'); document.body.style.overflow = 'hidden'; }
    function closeLightbox(event) { if (event.target.id === 'lightbox' || event.target.closest('.lightbox-close')) { event.stopPropagation(); document.getElementById('lightbox').classList.remove('active'); document.body.style.overflow = 'auto'; } }
    function changeImage(direction, event) { event.stopPropagation(); currentImageIndex += direction; if (currentImageIndex < 0) currentImageIndex = currentImages.length - 1; if (currentImageIndex >= currentImages.length) currentImageIndex = 0; updateLightboxImage(); }
    function updateLightboxImage() { document.getElementById('lightboxImage').src = '../' + currentImages[currentImageIndex]; document.getElementById('lightboxCounter').textContent = (currentImageIndex + 1) + ' / ' + currentImages.length; document.getElementById('lightboxLabel').textContent = currentHeroView === 'featured' ? 'Featured Photos' : 'Floor ' + currentHeroFloor + ' Photos'; }
    document.addEventListener('keydown', function(e) { const lb = document.getElementById('lightbox'); if (lb.classList.contains('active')) { if (e.key === 'Escape') closeLightbox({ target: lb }); if (e.key === 'ArrowLeft') changeImage(-1, e); if (e.key === 'ArrowRight') changeImage(1, e); } });

    // Price History See More/See Less
    const priceHistorySeeMoreBtn = document.getElementById('priceHistorySeeMoreBtn');
    if (priceHistorySeeMoreBtn) {
        let isExpanded = false;
        priceHistorySeeMoreBtn.addEventListener('click', function() {
            const extraItems = document.querySelectorAll('.price-history-extra');
            isExpanded = !isExpanded;
            
            extraItems.forEach(item => {
                if (isExpanded) {
                    item.style.display = 'block';
                    // Trigger animation
                    setTimeout(() => {
                        item.style.opacity = '1';
                        item.style.maxHeight = '300px';
                    }, 10);
                } else {
                    item.style.opacity = '0';
                    item.style.maxHeight = '0';
                    setTimeout(() => {
                        item.style.display = 'none';
                    }, 300);
                }
            });
            
            if (isExpanded) {
                this.innerHTML = '<i class="bi bi-chevron-up"></i> See Less';
            } else {
                const hiddenCount = extraItems.length;
                this.innerHTML = '<i class="bi bi-chevron-down"></i> See More (' + hiddenCount + ' older)';
            }
        });
    }
    </script>
    <!-- Modal logic (separated for performance) -->
    <script src="../script/agent_property_modals.js"></script>

<script>
// ===== TOAST =====
function showToast(type, title, message, duration) {
    duration = duration || 4500;
    var container = document.getElementById('toastContainer');
    var icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', info: 'bi-info-circle-fill' };
    var toast = document.createElement('div');
    toast.className = 'app-toast toast-' + type;
    toast.innerHTML =
        '<div class="app-toast-icon"><i class="bi ' + (icons[type] || icons.info) + '"></i></div>' +
        '<div class="app-toast-body">' +
            '<div class="app-toast-title">' + title + '</div>' +
            '<div class="app-toast-msg">' + message + '</div>' +
        '</div>' +
        '<button class="app-toast-close" onclick="dismissToast(this.closest(&quot;.app-toast&quot;))">&times;</button>' +
        '<div class="app-toast-progress" style="animation: toast-progress ' + duration + 'ms linear forwards;"></div>';
    container.appendChild(toast);
    var timer = setTimeout(function() { dismissToast(toast); }, duration);
    toast._timer = timer;
}
function dismissToast(toast) {
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    clearTimeout(toast._timer);
    toast.classList.add('toast-out');
    setTimeout(function() { toast.remove(); }, 320);
}
</script>

<!-- SKELETON HYDRATION — Progressive Content Reveal (Agent Portal) -->
<script>
(function () {
    'use strict';
    var MIN_SKELETON_MS = 400;
    var skeletonStart   = Date.now();
    function hydrate() {
        var elapsed   = Date.now() - skeletonStart;
        var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);
        setTimeout(function () {
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');
            if (!sk || !pc) return;
            pc.style.display    = 'block';
            pc.style.opacity    = '0';
            pc.style.transition = 'opacity 0.35s ease';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    pc.style.opacity    = '1';
                    sk.style.transition = 'opacity 0.25s ease';
                    sk.style.opacity    = '0';
                    setTimeout(function () {
                        sk.style.display = 'none';
                        document.dispatchEvent(new CustomEvent('skeleton:hydrated'));
                    }, 260);
                });
            });
        }, remaining);
    }
    if (document.readyState === 'complete') { hydrate(); }
    else { window.addEventListener('load', hydrate); }
}());
</script>

<!-- TOAST TRIGGERS — fire after real content is visible -->
<script>
document.addEventListener('skeleton:hydrated', function () {
    var toastDelay = 0;
    var TOAST_GAP  = 600;

    <?php if (!empty($success_message)): ?>
    setTimeout(function () {
        showToast('success', 'Success', '<?= addslashes($success_message) ?>', 5500);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
    setTimeout(function () {
        showToast('error', 'Error', '<?= addslashes($error_message) ?>', 6000);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>

    <?php if ($property_data['approval_status'] === 'rejected'): ?>
    // Listing was rejected — agent needs to fix and resubmit
    setTimeout(function () {
        showToast('error', 'Listing Rejected',
            'This property was rejected. Review the details and resubmit for admin approval.', 7000);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>

    <?php if ($sale_status === 'Rejected'): ?>
    // Sale verification rejected — needs resubmission
    setTimeout(function () {
        showToast('error', 'Sale Rejected',
            'The sale verification for this property was rejected. Please resubmit with the correct documents.', 7000);
    }, toastDelay);
    <?php endif; ?>
});
</script>
</body>
</html>
