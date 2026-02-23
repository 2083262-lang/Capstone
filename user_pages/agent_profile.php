<?php
include '../connection.php';

// Get agent account_id from URL
$agent_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error_message = '';
$agent = null;
$agent_properties = [];

if ($agent_id <= 0) {
    $error_message = 'Invalid agent ID.';
} else {
    // Fetch agent info
    $agent_sql = "
        SELECT 
            a.account_id, a.first_name, a.middle_name, a.last_name, a.email, a.phone_number, a.date_registered,
            ai.license_number, ai.specialization, ai.years_experience, ai.bio, ai.profile_picture_url
        FROM accounts a
        JOIN user_roles ur ON a.role_id = ur.role_id
        JOIN agent_information ai ON a.account_id = ai.account_id
        WHERE a.account_id = ? 
            AND ur.role_name = 'agent'
            AND ai.is_approved = 1 
            AND ai.profile_completed = 1
            AND a.is_active = 1
        LIMIT 1
    ";
    $stmt = $conn->prepare($agent_sql);
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $agent = $result->fetch_assoc();
    } else {
        $error_message = 'Agent not found or profile is not available.';
    }
    $stmt->close();

    // Fetch agent's listed properties (approved only)
    if ($agent) {
        $prop_sql = "
            SELECT 
                p.property_ID, p.StreetAddress, p.City, p.State, p.PropertyType,
                p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice, p.Status,
                p.ListingDate, COALESCE(p.ViewsCount, 0) AS ViewsCount, COALESCE(p.Likes, 0) AS Likes,
                (SELECT pi.PhotoURL FROM property_images pi WHERE pi.property_ID = p.property_ID ORDER BY pi.SortOrder ASC LIMIT 1) AS PhotoURL
            FROM property p
            JOIN property_log pl ON pl.property_id = p.property_ID
            WHERE pl.account_id = ? AND pl.action = 'CREATED' AND p.approval_status = 'approved'
            ORDER BY p.ListingDate DESC
        ";
        $stmt2 = $conn->prepare($prop_sql);
        $stmt2->bind_param("i", $agent_id);
        $stmt2->execute();
        $prop_result = $stmt2->get_result();
        $agent_properties = $prop_result->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();

        // Fetch completed sales count
        $sales_sql = "SELECT COUNT(*) as total FROM finalized_sales WHERE agent_id = ?";
        $stmt3 = $conn->prepare($sales_sql);
        $stmt3->bind_param("i", $agent_id);
        $stmt3->execute();
        $sales_count = $stmt3->get_result()->fetch_assoc()['total'];
        $stmt3->close();

        // Fetch completed tours count
        $tours_sql = "SELECT COUNT(*) as total FROM tour_requests WHERE agent_account_id = ? AND request_status = 'Completed'";
        $stmt4 = $conn->prepare($tours_sql);
        $stmt4->bind_param("i", $agent_id);
        $stmt4->execute();
        $tours_count = $stmt4->get_result()->fetch_assoc()['total'];
        $stmt4->close();
    }
}

// Prepare agent display data
if ($agent) {
    // Build full name with middle name
    $name_parts = [$agent['first_name']];
    if (!empty($agent['middle_name'])) {
        $name_parts[] = $agent['middle_name'];
    }
    $name_parts[] = $agent['last_name'];
    $full_name = htmlspecialchars(trim(implode(' ', $name_parts)));
    
    $email = htmlspecialchars($agent['email']);
    $phone = htmlspecialchars($agent['phone_number'] ?? '');
    $license = htmlspecialchars($agent['license_number'] ?? 'N/A');
    $specialization = htmlspecialchars($agent['specialization'] ?? 'Real Estate Professional');
    $years = (int)$agent['years_experience'];
    $experience_text = $years === 0 ? 'New Agent' : ($years === 1 ? '1 Year' : $years . ' Years');
    $bio = htmlspecialchars($agent['bio'] ?? 'Dedicated real estate professional committed to helping you find your perfect property.');
    $profile_pic = !empty($agent['profile_picture_url'])
        ? '../' . htmlspecialchars($agent['profile_picture_url'])
        : 'https://via.placeholder.com/200?text=' . strtoupper(substr($agent['first_name'], 0, 1));
    $member_since = !empty($agent['date_registered']) ? date('F Y', strtotime($agent['date_registered'])) : 'N/A';

    // Stats
    $total_listings = count($agent_properties);
    $for_sale = 0;
    $for_rent = 0;
    $sold = 0;
    foreach ($agent_properties as $p) {
        if ($p['Status'] === 'For Sale') $for_sale++;
        if ($p['Status'] === 'For Rent') $for_rent++;
        if ($p['Status'] === 'Sold') $sold++;
    }
    $completed_sales = $sales_count ?? 0;
    $completed_tours = $tours_count ?? 0;

    // Fetch similar agents (by specialization) or random agents
    $similar_agents = [];
    $similar_sql = "
        SELECT 
            a.account_id, a.first_name, a.last_name,
            ai.license_number, ai.specialization, ai.years_experience, ai.profile_picture_url,
            (SELECT COUNT(*) FROM property p 
             JOIN property_log pl ON pl.property_id = p.property_ID 
             WHERE pl.account_id = a.account_id AND pl.action = 'CREATED' AND p.approval_status = 'approved') as listing_count,
            (SELECT COUNT(*) FROM finalized_sales WHERE agent_id = a.account_id) as sales_count
        FROM accounts a
        JOIN user_roles ur ON a.role_id = ur.role_id
        JOIN agent_information ai ON a.account_id = ai.account_id
        WHERE a.account_id != ? 
            AND ur.role_name = 'agent'
            AND ai.is_approved = 1 
            AND ai.profile_completed = 1
            AND a.is_active = 1
        ORDER BY 
            CASE WHEN ai.specialization = ? THEN 0 ELSE 1 END,
            RAND()
        LIMIT 4
    ";
    $stmt5 = $conn->prepare($similar_sql);
    $stmt5->bind_param("is", $agent_id, $agent['specialization']);
    $stmt5->execute();
    $similar_result = $stmt5->get_result();
    $similar_agents = $similar_result->fetch_all(MYSQLI_ASSOC);
    $stmt5->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $agent ? $full_name . ' - Agent Profile' : 'Agent Profile'; ?> | HomeEstate Realty</title>
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

        /* Breadcrumb */
        .breadcrumb-section {
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.95) 0%, rgba(15, 15, 15, 0.98) 100%);
            padding: 20px 0;
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            margin-top: 35px;
        }
        .breadcrumb { background: transparent; margin: 0; padding: 0; font-size: 0.875rem; }
        .breadcrumb-item { color: var(--gray-400); }
        .breadcrumb-item a { color: var(--blue-light); text-decoration: none; transition: color 0.2s ease; }
        .breadcrumb-item a:hover { color: var(--blue); }
        .breadcrumb-item.active { color: var(--gray-300); }
        .breadcrumb-item + .breadcrumb-item::before { color: var(--gray-600); }

        /* Profile Header */
        .profile-header {
            padding: 60px 0;
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(10, 10, 10, 0.98) 100%);
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
        }

        .profile-header-inner {
            display: flex;
            gap: 48px;
            align-items: flex-start;
        }

        .profile-avatar-wrapper {
            flex-shrink: 0;
        }

        .profile-avatar {
            width: 200px;
            height: 200px;
            border-radius: 6px;
            object-fit: cover;
            border: 3px solid rgba(212, 175, 55, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }

        .profile-details {
            flex: 1;
            min-width: 0;
        }

        .profile-name {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .profile-specialization {
            font-size: 1.125rem;
            color: var(--gold);
            font-weight: 600;
            margin-bottom: 20px;
        }

        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin-bottom: 24px;
        }

        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9375rem;
            color: var(--gray-300);
        }

        .profile-meta-item i {
            color: var(--blue-light);
            font-size: 1rem;
        }

        .profile-meta-item strong {
            color: var(--white);
            font-weight: 600;
        }

        /* Stats Row */
        .profile-stats {
            display: flex;
            gap: 16px;
            margin-bottom: 28px;
        }

        .profile-stat {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            padding: 16px 24px;
            text-align: center;
            min-width: 120px;
        }

        .profile-stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gold);
            line-height: 1;
            margin-bottom: 4px;
        }

        .profile-stat-label {
            font-size: 0.75rem;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        /* Contact Buttons */
        .profile-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .profile-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 4px;
            font-size: 0.9375rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }

        .profile-action-btn.primary {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            color: var(--black);
            box-shadow: 0 2px 8px rgba(212, 175, 55, 0.2);
        }

        .profile-action-btn.primary:hover {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.35);
            color: var(--black);
        }

        .profile-action-btn.secondary {
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.3);
            color: var(--blue-light);
        }

        .profile-action-btn.secondary:hover {
            background: rgba(37, 99, 235, 0.2);
            border-color: var(--blue);
            transform: translateY(-1px);
            color: var(--blue-light);
        }

        /* Content Layout */
        .profile-content {
            padding: 48px 0 80px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 36px;
            align-items: start;
        }

        /* Content Cards */
        .content-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(17, 17, 17, 0.98) 100%);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 4px;
            padding: 32px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .content-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--blue) 0%, var(--gold) 50%, var(--blue) 100%);
            opacity: 0.5;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: var(--blue-light);
            font-size: 1.25rem;
        }

        /* Bio Section */
        .bio-text {
            font-size: 1rem;
            color: var(--gray-300);
            line-height: 1.8;
            white-space: pre-wrap;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .info-item {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 4px;
            padding: 18px 20px;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .info-value {
            font-size: 1rem;
            color: var(--white);
            font-weight: 600;
        }

        .info-value a {
            color: var(--blue-light);
            text-decoration: none;
        }

        .info-value a:hover {
            color: var(--blue);
            text-decoration: underline;
        }

        /* Specialization Tags */
        .spec-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .spec-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 3px;
            font-size: 0.8125rem;
            color: var(--gold);
            font-weight: 600;
        }

        .spec-tag i {
            font-size: 0.75rem;
        }

        /* Sidebar */
        .sidebar-sticky {
            position: sticky;
            top: 100px;
        }

        .sidebar-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(17, 17, 17, 0.98) 100%);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 4px;
            padding: 28px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .sidebar-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--gold) 0%, var(--blue) 100%);
            opacity: 0.5;
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sidebar-title i {
            color: var(--gold);
            font-size: 1.125rem;
        }

        .sidebar-contact-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 4px;
            margin-bottom: 12px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
        }

        .sidebar-contact-item:hover {
            background: rgba(37, 99, 235, 0.08);
            border-color: rgba(37, 99, 235, 0.2);
            color: inherit;
        }

        .sidebar-contact-icon {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 4px;
            flex-shrink: 0;
        }

        .sidebar-contact-icon i {
            font-size: 1.125rem;
            color: var(--blue-light);
        }

        .sidebar-contact-text {
            flex: 1;
            min-width: 0;
        }

        .sidebar-contact-label {
            font-size: 0.6875rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .sidebar-contact-value {
            font-size: 0.9375rem;
            color: var(--white);
            font-weight: 600;
            word-break: break-word;
        }

        .sidebar-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .sidebar-info-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .sidebar-info-label {
            font-size: 0.875rem;
            color: var(--gray-400);
        }

        .sidebar-info-value {
            font-size: 0.9375rem;
            color: var(--white);
            font-weight: 600;
        }

        /* Properties Section */
        .properties-section-title {
            padding-top: 1rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 8px;
        }

        .properties-section-desc {
            font-size: 0.9375rem;
            color: var(--gray-400);
            margin-bottom: 28px;
        }

        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .property-card-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .prop-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(17, 17, 17, 0.98) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            overflow: hidden;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .prop-card:hover {
            transform: translateY(-3px);
            border-color: rgba(37, 99, 235, 0.3);
        }

        .prop-card-img {
            position: relative;
            height: 200px;
            overflow: hidden;
            background: var(--black);
        }

        .prop-card-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s ease;
        }

        .prop-card:hover .prop-card-img img {
            transform: scale(1.03);
        }

        .prop-card-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 5px 14px;
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
            color: var(--white);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 2px;
        }

        .prop-card-badge.for-rent {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
            color: var(--black);
        }

        .prop-card-stats {
            position: absolute;
            bottom: 10px;
            right: 10px;
            display: flex;
            gap: 8px;
        }

        .prop-stat-badge {
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

        .prop-stat-badge i { font-size: 0.6875rem; }
        .prop-stat-badge.views i { color: var(--blue-light); }
        .prop-stat-badge.likes i { color: #ef4444; }

        .prop-card-body {
            padding: 20px;
        }

        .prop-card-price {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--gold);
            margin-bottom: 8px;
        }

        .prop-card-address {
            font-size: 0.875rem;
            color: var(--gray-300);
            display: flex;
            align-items: flex-start;
            gap: 6px;
            margin-bottom: 14px;
            line-height: 1.4;
        }

        .prop-card-address i {
            color: var(--blue-light);
            margin-top: 2px;
            flex-shrink: 0;
        }

        .prop-card-features {
            display: flex;
            gap: 16px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .prop-feature {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8125rem;
            color: var(--gray-400);
            font-weight: 500;
        }

        .prop-feature i {
            color: var(--blue-light);
            font-size: 0.875rem;
        }

        /* Empty Properties */
        .empty-properties {
            text-align: center;
            padding: 60px 20px;
            background: rgba(0, 0, 0, 0.15);
            border: 1px dashed rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }

        .empty-properties i {
            font-size: 3rem;
            color: var(--gray-600);
            margin-bottom: 16px;
        }

        .empty-properties h4 {
            font-size: 1.125rem;
            color: var(--gray-300);
            font-weight: 600;
            margin-bottom: 6px;
        }

        .empty-properties p {
            font-size: 0.9375rem;
            color: var(--gray-500);
        }

        /* Similar Agents Section */
        .similar-agents-section {
            padding: 60px 0 80px;
            border-top: 1px solid rgba(37, 99, 235, 0.15);
        }

        .similar-agents-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .similar-agents-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 8px;
        }

        .similar-agents-subtitle {
            font-size: 1rem;
            color: var(--gray-400);
        }

        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
        }

        .agent-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(17, 17, 17, 0.98) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .agent-card:hover {
            transform: translateY(-4px);
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }

        .agent-card-avatar-wrapper {
            position: relative;
            height: 200px;
            background: var(--black);
            overflow: hidden;
        }

        .agent-card-avatar {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s ease;
        }

        .agent-card:hover .agent-card-avatar {
            transform: scale(1.05);
        }

        .agent-card-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 5px 12px;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
            color: var(--black);
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .agent-card-badge i {
            font-size: 0.625rem;
        }

        .agent-card-body {
            padding: 20px;
        }

        .agent-card-name {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 6px;
            line-height: 1.3;
        }

        .agent-card-spec {
            font-size: 0.8125rem;
            color: var(--gold);
            margin-bottom: 14px;
            font-weight: 600;
        }

        .agent-card-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .agent-card-stat {
            text-align: center;
        }

        .agent-card-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--blue-light);
            line-height: 1;
            margin-bottom: 4px;
        }

        .agent-card-stat-label {
            font-size: 0.6875rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Error State */
        .error-container {
            text-align: center;
            padding: 120px 20px;
        }

        .error-container i {
            font-size: 4rem;
            color: var(--gray-600);
            margin-bottom: 24px;
        }

        .error-container h2 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 12px;
        }

        .error-container p {
            font-size: 1rem;
            color: var(--gray-400);
            margin-bottom: 32px;
        }

        .error-container a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            color: var(--black);
            font-weight: 700;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .error-container a:hover {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            transform: translateY(-1px);
            color: var(--black);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            .sidebar-sticky {
                position: static;
            }
        }

        @media (max-width: 768px) {
            .profile-header-inner {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 28px;
            }

            .profile-avatar { width: 160px; height: 160px; }
            .profile-name { font-size: 1.75rem; }
            .profile-meta { justify-content: center; }
            .profile-stats { justify-content: center; flex-wrap: wrap; }
            .profile-actions { justify-content: center; }
            .info-grid { grid-template-columns: 1fr; }
            .properties-grid { grid-template-columns: 1fr; }
            .spec-tags { justify-content: center; }
        }

        @media (max-width: 576px) {
            .profile-stat { min-width: 100px; padding: 12px 16px; }
            .profile-stat-value { font-size: 1.5rem; }
            .profile-action-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<!-- Breadcrumb -->
<div class="breadcrumb-section">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="agents.php">Agents</a></li>
                <li class="breadcrumb-item active"><?php echo $agent ? $full_name : 'Agent Profile'; ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($error_message): ?>
    <div class="container">
        <div class="error-container">
            <i class="bi bi-person-x"></i>
            <h2>Agent Not Found</h2>
            <p><?php echo htmlspecialchars($error_message); ?></p>
            <a href="agents.php"><i class="bi bi-arrow-left"></i> Back to Agents</a>
        </div>
    </div>
<?php else: ?>

<!-- Profile Header -->
<section class="profile-header">
    <div class="container">
        <div class="profile-header-inner">
            <div class="profile-avatar-wrapper">
                <img src="<?php echo $profile_pic; ?>" alt="<?php echo $full_name; ?>" class="profile-avatar">
            </div>
            <div class="profile-details">
                <h1 class="profile-name"><?php echo $full_name; ?></h1>
                <p class="profile-specialization"><?php echo $specialization; ?></p>

                <div class="profile-meta">
                    <div class="profile-meta-item">
                        <i class="bi bi-briefcase-fill"></i>
                        <strong><?php echo $experience_text; ?></strong> Experience
                    </div>
                    <div class="profile-meta-item">
                        <i class="bi bi-patch-check-fill"></i>
                        License: <strong><?php echo $license; ?></strong>
                    </div>
                    <div class="profile-meta-item">
                        <i class="bi bi-calendar-check"></i>
                        Member since <strong><?php echo $member_since; ?></strong>
                    </div>
                </div>

                <div class="profile-stats">
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $total_listings; ?></div>
                        <div class="profile-stat-label">Listings</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $completed_sales; ?></div>
                        <div class="profile-stat-label">Sales Closed</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $completed_tours; ?></div>
                        <div class="profile-stat-label">Tours Done</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $experience_text; ?></div>
                        <div class="profile-stat-label">Experience</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Profile Content -->
<section class="profile-content">
    <div class="container">
        <div class="profile-grid">
            <!-- Main Content -->
            <div>
                <!-- About -->
                <div class="content-card">
                    <h2 class="card-title"><i class="bi bi-person-lines-fill"></i> About <?php echo htmlspecialchars($agent['first_name']); ?></h2>
                    <p class="bio-text"><?php echo nl2br($bio); ?></p>
                </div>

                <!-- Professional Details -->
                <div class="content-card">
                    <h2 class="card-title"><i class="bi bi-briefcase-fill"></i> Professional Details</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">License Number</div>
                            <div class="info-value"><?php echo $license; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Years of Experience</div>
                            <div class="info-value"><?php echo $experience_text; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Active Listings</div>
                            <div class="info-value"><?php echo $total_listings; ?> Properties</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Sales Closed</div>
                            <div class="info-value"><?php echo $completed_sales; ?> Transaction<?php echo $completed_sales !== 1 ? 's' : ''; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Tours Completed</div>
                            <div class="info-value"><?php echo $completed_tours; ?> Tour<?php echo $completed_tours !== 1 ? 's' : ''; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Member Since</div>
                            <div class="info-value"><?php echo $member_since; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Specializations -->
                <?php
                $spec_list = array_filter(array_map('trim', explode(',', $specialization)));
                if (count($spec_list) > 0):
                ?>
                <div class="content-card">
                    <h2 class="card-title"><i class="bi bi-award-fill"></i> Specializations</h2>
                    <div class="spec-tags">
                        <?php foreach ($spec_list as $spec): ?>
                            <span class="spec-tag">
                                <i class="bi bi-check-circle-fill"></i>
                                <?php echo htmlspecialchars($spec); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Agent's Listings -->
                <div class="content-card" style="border: none; background: transparent; padding: 0; margin-top: 16px;">
                    <h2 class="properties-section-title">
                        <i class="bi bi-houses-fill" style="color: var(--blue-light);"></i>
                        <?php echo htmlspecialchars($agent['first_name']); ?>'s Listings
                    </h2>
                    <p class="properties-section-desc">
                        Browse <?php echo $total_listings; ?> propert<?php echo $total_listings !== 1 ? 'ies' : 'y'; ?> listed by this agent.
                    </p>

                    <?php if (count($agent_properties) > 0): ?>
                        <div class="properties-grid">
                            <?php foreach ($agent_properties as $prop): ?>
                                <a href="property_details.php?id=<?php echo $prop['property_ID']; ?>" class="property-card-link">
                                    <div class="prop-card">
                                        <div class="prop-card-img">
                                            <img src="../<?php echo htmlspecialchars($prop['PhotoURL'] ?? 'images/placeholder.jpg'); ?>" 
                                                 alt="Property" loading="lazy">
                                            <div class="prop-card-badge <?php echo $prop['Status'] === 'For Rent' ? 'for-rent' : ''; ?>">
                                                <?php echo htmlspecialchars($prop['Status']); ?>
                                            </div>
                                            <div class="prop-card-stats">
                                                <div class="prop-stat-badge views">
                                                    <i class="bi bi-eye-fill"></i>
                                                    <?php echo number_format($prop['ViewsCount']); ?>
                                                </div>
                                                <div class="prop-stat-badge likes">
                                                    <i class="bi bi-heart-fill"></i>
                                                    <?php echo number_format($prop['Likes']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="prop-card-body">
                                            <div class="prop-card-price">₱<?php echo number_format($prop['ListingPrice']); ?></div>
                                            <div class="prop-card-address">
                                                <i class="bi bi-geo-alt-fill"></i>
                                                <span><?php echo htmlspecialchars($prop['StreetAddress']); ?>, <?php echo htmlspecialchars($prop['City']); ?></span>
                                            </div>
                                            <div class="prop-card-features">
                                                <div class="prop-feature">
                                                    <i class="bi bi-door-open-fill"></i>
                                                    <?php echo $prop['Bedrooms']; ?> Beds
                                                </div>
                                                <div class="prop-feature">
                                                    <i class="bi bi-droplet-fill"></i>
                                                    <?php echo $prop['Bathrooms']; ?> Baths
                                                </div>
                                                <div class="prop-feature">
                                                    <i class="bi bi-arrows-fullscreen"></i>
                                                    <?php echo number_format($prop['SquareFootage']); ?> ft²
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-properties">
                            <i class="bi bi-houses"></i>
                            <h4>No Listings Yet</h4>
                            <p>This agent hasn't listed any properties yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <aside>
                <div class="sidebar-sticky">
                    <!-- Contact Info -->
                    <div class="sidebar-card">
                        <h3 class="sidebar-title"><i class="bi bi-telephone-forward-fill"></i> Contact Information</h3>
                        
                        <a href="mailto:<?php echo $email; ?>" class="sidebar-contact-item">
                            <div class="sidebar-contact-icon">
                                <i class="bi bi-envelope-fill"></i>
                            </div>
                            <div class="sidebar-contact-text">
                                <div class="sidebar-contact-label">Email</div>
                                <div class="sidebar-contact-value"><?php echo $email; ?></div>
                            </div>
                        </a>

                        <?php if (!empty($phone)): ?>
                        <a href="tel:<?php echo $phone; ?>" class="sidebar-contact-item">
                            <div class="sidebar-contact-icon">
                                <i class="bi bi-telephone-fill"></i>
                            </div>
                            <div class="sidebar-contact-text">
                                <div class="sidebar-contact-label">Phone</div>
                                <div class="sidebar-contact-value"><?php echo $phone; ?></div>
                            </div>
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Info -->
                    <div class="sidebar-card">
                        <h3 class="sidebar-title"><i class="bi bi-info-circle-fill"></i> Quick Info</h3>
                        
                        <div class="sidebar-info-row">
                            <span class="sidebar-info-label">Experience</span>
                            <span class="sidebar-info-value"><?php echo $experience_text; ?></span>
                        </div>
                        <div class="sidebar-info-row">
                            <span class="sidebar-info-label">License</span>
                            <span class="sidebar-info-value"><?php echo $license; ?></span>
                        </div>
                        <div class="sidebar-info-row">
                            <span class="sidebar-info-label">Active Listings</span>
                            <span class="sidebar-info-value"><?php echo $total_listings; ?></span>
                        </div>
                        <div class="sidebar-info-row">
                            <span class="sidebar-info-label">For Sale</span>
                            <span class="sidebar-info-value"><?php echo $for_sale; ?></span>
                        </div>
                        <div class="sidebar-info-row">
                            <span class="sidebar-info-label">For Rent</span>
                            <span class="sidebar-info-value"><?php echo $for_rent; ?></span>
                        </div>
                        <div class="sidebar-info-row">
                            <span class="sidebar-info-label">Sales Closed</span>
                            <span class="sidebar-info-value"><?php echo $completed_sales; ?></span>
                        </div>
                        <div class="sidebar-info-row">
                            <span class="sidebar-info-label">Tours Done</span>
                            <span class="sidebar-info-value"><?php echo $completed_tours; ?></span>
                        </div>
                        <div class="sidebar-info-row">
                            <span class="sidebar-info-label">Member Since</span>
                            <span class="sidebar-info-value"><?php echo $member_since; ?></span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>

<!-- Similar Agents Section -->
<?php if (!empty($similar_agents) && count($similar_agents) > 0): ?>
<section class="similar-agents-section">
    <div class="container">
        <div class="similar-agents-header">
            <h2 class="similar-agents-title">Meet Other Agents</h2>
            <p class="similar-agents-subtitle">Discover more real estate professionals who can help you</p>
        </div>

        <div class="agents-grid">
            <?php foreach ($similar_agents as $sim_agent): 
                $sim_name = htmlspecialchars(trim($sim_agent['first_name'] . ' ' . $sim_agent['last_name']));
                $sim_spec = htmlspecialchars($sim_agent['specialization'] ?? 'Real Estate Professional');
                $sim_years = (int)$sim_agent['years_experience'];
                $sim_exp = $sim_years === 0 ? 'New Agent' : ($sim_years === 1 ? '1 Year' : $sim_years . ' Years');
                $sim_pic = !empty($sim_agent['profile_picture_url'])
                    ? '../' . htmlspecialchars($sim_agent['profile_picture_url'])
                    : 'https://via.placeholder.com/200?text=' . strtoupper(substr($sim_agent['first_name'], 0, 1));
                $sim_listings = (int)$sim_agent['listing_count'];
                $sim_sales = (int)$sim_agent['sales_count'];
            ?>
                <a href="agent_profile.php?id=<?php echo $sim_agent['account_id']; ?>" class="agent-card">
                    <div class="agent-card-avatar-wrapper">
                        <img src="<?php echo $sim_pic; ?>" alt="<?php echo $sim_name; ?>" class="agent-card-avatar">
                        <div class="agent-card-badge">
                            <i class="bi bi-patch-check-fill"></i> Verified
                        </div>
                    </div>
                    <div class="agent-card-body">
                        <h3 class="agent-card-name"><?php echo $sim_name; ?></h3>
                        <p class="agent-card-spec"><?php echo $sim_spec; ?></p>
                        <div class="agent-card-stats">
                            <div class="agent-card-stat">
                                <div class="agent-card-stat-value"><?php echo $sim_listings; ?></div>
                                <div class="agent-card-stat-label">Listings</div>
                            </div>
                            <div class="agent-card-stat">
                                <div class="agent-card-stat-value"><?php echo $sim_sales; ?></div>
                                <div class="agent-card-stat-label">Sales</div>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
