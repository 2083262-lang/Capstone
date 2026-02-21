<?php
session_start();
include 'connection.php'; // Include your database connection

// Check if the user is logged in AND their role is 'admin'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// --- FILTERING LOGIC (remains the same) ---
$filter_conditions = [];
$filter_params = [];
$filter_types = '';
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['apply_filters'])) {
    // (Your existing filtering code would go here)
}

// --- CORRECTED SQL QUERY ---
// This query has been reverted to your required method, using property_log.
$sql = "SELECT 
            p.*, 
            pi.PhotoURL,
            a.first_name AS poster_first_name,
            ur.role_name AS poster_role_name,
            rd.monthly_rent AS rd_monthly_rent,
            rd.security_deposit AS rd_security_deposit,
            rd.lease_term_months AS rd_lease_term_months,
            rd.furnishing AS rd_furnishing,
            rd.available_from AS rd_available_from
        FROM property p
        LEFT JOIN property_images pi ON p.property_ID = pi.property_ID AND pi.SortOrder = 1
        LEFT JOIN property_log pl ON p.property_ID = pl.property_id AND pl.action = 'CREATED'
        LEFT JOIN accounts a ON pl.account_id = a.account_id
        LEFT JOIN user_roles ur ON a.role_id = ur.role_id
        LEFT JOIN rental_details rd ON rd.property_id = p.property_ID";

if (!empty($filter_conditions)) {
    $sql .= " WHERE " . implode(' AND ', $filter_conditions);
}

$sql .= " GROUP BY p.property_ID ORDER BY FIELD(p.approval_status, 'pending', 'approved', 'rejected'), p.ListingDate DESC";


$properties = [];
if ($stmt = $conn->prepare($sql)) {
    if (!empty($filter_params)) {
        // (Your existing parameter binding logic for filters remains here)
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $properties = $result->fetch_all(MYSQLI_ASSOC);
    } else { 
        echo "Error executing query: " . $stmt->error; 
    }
    $stmt->close();
} else { 
    echo "Error preparing query: " . $conn->error; 
}

// --- Separate properties into categories ---
// Note: 'approval_status' is the admin workflow status (pending/approved/rejected)
// 'Status' is the listing status (For Sale/For Rent/Sold)
$pending_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'pending' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold'])));
$approved_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'approved' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold'])));
$rejected_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'rejected' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold'])));
// Pending Sold is represented by Status = 'Pending Sold'; Sold is Status = 'Sold'
$pending_sold_properties = array_filter($properties, fn($p) => isset($p['Status']) && strtolower(trim($p['Status'])) === 'pending sold');
$sold_properties = array_filter($properties, fn($p) => isset($p['Status']) && strtolower(trim($p['Status'])) === 'sold');


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --background-color: #f8f9fa;
            --card-bg-color: #ffffff;
            --border-color: #e9ecef;
            --text-muted: #6c757d;
            --shadow-light: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-medium: 0 4px 20px rgba(0,0,0,0.12);
            --shadow-heavy: 0 8px 32px rgba(0,0,0,0.15);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--background-color);
            color: var(--primary-color);
        }

        /* Main Content Styling */
        .main-content { 
            margin-left: var(--sidebar-width); 
            padding: var(--content-padding); 
            background-color: var(--background-color);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #2c241a 100%);
            border-radius: 20px;
            padding: 3rem 2rem;
            margin-bottom: 2rem;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(188, 158, 66, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .page-header-content {
            position: relative;
            z-index: 2;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 0;
        }

        /* Action Bar */
        .action-bar {
            background: var(--card-bg-color);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .action-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn-modern {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary-modern {
            background: linear-gradient(135deg, var(--secondary-color), #a08636);
            color: #fff;
            box-shadow: 0 2px 8px rgba(188, 158, 66, 0.3);
        }

        .btn-primary-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.4);
            background: linear-gradient(135deg, #a08636, var(--secondary-color));
        }

        .btn-secondary-modern {
            background: #fff;
            color: var(--text-muted);
            border: 2px solid var(--border-color);
        }

        .btn-secondary-modern:hover {
            border-color: var(--secondary-color);
            color: var(--secondary-color);
            background: rgba(188, 158, 66, 0.05);
        }

        /* Tabs Styling */
        .property-tabs {
            background: var(--card-bg-color);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: var(--shadow-light);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .nav-tabs {
            border-bottom: none;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 0.5rem;
        }

        .nav-tabs .nav-item {
            margin-bottom: 0;
        }

        .nav-tabs .nav-link {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 1rem;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 12px;
            margin-right: 0.5rem;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-tabs .nav-link:hover {
            background: rgba(188, 158, 66, 0.1);
            color: var(--secondary-color);
        }

        .nav-tabs .nav-link.active {
            background: var(--secondary-color);
            color: #fff;
            box-shadow: 0 2px 8px rgba(188, 158, 66, 0.3);
        }

        .tab-badge {
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            margin-left: 0.5rem;
        }

        .badge-pending {
            background: linear-gradient(135deg, #ffc107, #ffb300);
            color: #000;
        }

        .badge-approved {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: #fff;
        }

        .badge-rejected {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: #fff;
        }

        /* Tab Content */
        .tab-content {
            padding: 2rem;
        }

        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 0;
        }

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow-light);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-weight: 600;
            color: var(--text-muted);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding-top: 90px;
            }
            
            .page-header {
                padding: 2rem 1rem;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .action-bar {
                padding: 1rem;
                text-align: center;
            }
            
            .action-buttons {
                justify-content: center;
                width: 100%;
            }
            
            .properties-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 576px) {
            .nav-tabs .nav-link {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
        }

        /* Filter Sidebar Styles - Optimized & Professional */
        .filter-sidebar {
            position: fixed;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            pointer-events: none;
        }
        
        .filter-sidebar.active {
            pointer-events: all;
        }
        
        .filter-sidebar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            opacity: 0;
            transition: opacity 0.2s ease;
            pointer-events: none;
        }
        
        .filter-sidebar.active .filter-sidebar-overlay {
            opacity: 1;
            pointer-events: all;
        }
        
        .filter-sidebar-content {
            position: absolute;
            top: 0;
            right: 0;
            width: 500px;
            max-width: 90vw;
            height: 100%;
            background: #ffffff;
            box-shadow: -10px 0 40px rgba(0, 0, 0, 0.15);
            transform: translateX(100%);
            transition: transform 0.25s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .filter-sidebar.active .filter-sidebar-content {
            transform: translateX(0);
        }
        
        .filter-header {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            color: #ffffff;
            padding: 1.75rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid var(--secondary-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .filter-header h4 {
            font-weight: 700;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
            letter-spacing: -0.01em;
        }
        
        .filter-header h4 i {
            color: var(--secondary-color);
            font-size: 1.5rem;
        }
        
        .btn-close-filter {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.2s ease, border-color 0.2s ease;
            font-size: 1.125rem;
        }
        
        .btn-close-filter:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: var(--secondary-color);
        }
        
        .filter-body {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
            background: #f8f9fa;
        }
        
        .filter-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .filter-body::-webkit-scrollbar-track {
            background: #e5e7eb;
            border-radius: 10px;
        }
        
        .filter-body::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 10px;
        }
        
        .filter-body::-webkit-scrollbar-thumb:hover {
            background: #a08636;
        }
        
        .filter-section {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        
        .filter-section:last-child {
            margin-bottom: 0;
        }
        
        .filter-section-title {
            font-weight: 700;
            font-size: 0.875rem;
            color: #111827;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .filter-section-title i {
            color: var(--secondary-color);
            font-size: 1.125rem;
            flex-shrink: 0;
        }
        
        .filter-search-box {
            position: relative;
        }
        
        .filter-search-box input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 3rem;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            background: #ffffff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            font-size: 0.9375rem;
            color: #111827;
        }
        
        .filter-search-box input::placeholder {
            color: #9ca3af;
        }
        
        .filter-search-box input:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
            outline: none;
        }
        
        .filter-search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 1.125rem;
        }
        
        .price-slider-container {
            position: relative;
            height: 50px;
            margin-bottom: 1.5rem;
        }
        
        .price-slider-track {
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 8px;
            background: #e5e7eb;
            border-radius: 9999px;
            transform: translateY(-50%);
        }
        
        .price-slider-range {
            position: absolute;
            height: 100%;
            background: linear-gradient(90deg, var(--secondary-color), #d4b555);
            border-radius: 9999px;
            transition: left 0.1s ease, width 0.1s ease;
        }
        
        .price-range-slider {
            position: absolute;
            width: 100%;
            height: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            pointer-events: none;
            -webkit-appearance: none;
            appearance: none;
        }
        
        .price-range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            border-radius: 9999px;
            background: #ffffff;
            border: 4px solid var(--secondary-color);
            cursor: pointer;
            pointer-events: all;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        }
        
        .price-range-slider::-webkit-slider-thumb:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .price-range-slider::-webkit-slider-thumb:active {
            border-width: 5px;
        }
        
        .price-range-slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            border-radius: 9999px;
            background: #ffffff;
            border: 4px solid var(--secondary-color);
            cursor: pointer;
            pointer-events: all;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        }
        
        .price-range-slider::-moz-range-thumb:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .price-range-slider::-moz-range-thumb:active {
            border-width: 5px;
        }
        
        .price-range-inputs {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 1rem;
            align-items: center;
        }
        
        .price-input {
            position: relative;
        }
        
        .price-input input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.25rem;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            background: #ffffff;
            font-size: 0.9375rem;
            font-weight: 600;
            color: #111827;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        
        .price-input input:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
            outline: none;
        }
        
        .price-input .currency-symbol {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-weight: 600;
        }
        
        .range-divider {
            color: var(--text-muted);
            font-weight: 600;
        }
        
        .filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.625rem;
        }
        
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.125rem;
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 9999px;
            cursor: pointer;
            transition: border-color 0.2s ease, background-color 0.2s ease;
            font-size: 0.875rem;
            font-weight: 500;
            color: #111827;
        }
        
        .filter-chip:hover {
            background: #f9fafb;
            border-color: var(--secondary-color);
        }
        
        .filter-chip.active {
            background: var(--secondary-color);
            color: #ffffff;
            border-color: var(--secondary-color);
            font-weight: 600;
        }
        
        .filter-chip input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .filter-range-slider {
            margin-top: 1rem;
        }
        
        .filter-range-slider input[type="range"] {
            width: 100%;
            height: 6px;
            border-radius: 10px;
            background: linear-gradient(to right, var(--secondary-color), var(--secondary-color));
            outline: none;
            -webkit-appearance: none;
        }
        
        .filter-range-slider input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--secondary-color);
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        
        .filter-range-slider input[type="range"]::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--secondary-color);
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            border: none;
        }
        
        .range-values {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-muted);
            font-weight: 600;
        }
        
        .filter-footer {
            padding: 1.5rem 2rem;
            background: #ffffff;
            border-top: 2px solid #e5e7eb;
            display: flex;
            gap: 1rem;
        }
        
        .filter-footer .btn {
            flex: 1;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            border-radius: 10px;
            transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
            font-size: 0.9375rem;
        }
        
        .filter-footer .btn:hover {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .filter-results-summary {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.05));
            border: 2px solid rgba(188, 158, 66, 0.3);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .filter-results-summary i {
            color: var(--secondary-color);
            font-size: 1.5rem;
        }
        
        .filter-results-text {
            flex: 1;
        }
        
        .filter-results-count {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--secondary-color);
        }
        
        .filter-results-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        .filter-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: #dc3545;
            color: white;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: 0.5rem;
        }
        
        .filter-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            background: #ffffff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            font-size: 0.9375rem;
            font-weight: 500;
            color: #111827;
        }
        
        .filter-select:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
            outline: none;
        }
        
        .year-inputs {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 0.75rem;
            align-items: center;
        }
        
        .year-inputs input,
        .year-inputs input[type="date"] {
            width: 100%;
            min-width: 0;
            padding: 0.75rem 0.875rem;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            background: #ffffff;
            font-size: 0.875rem;
            font-weight: 500;
            color: #111827;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        
        .year-inputs input:focus,
        .year-inputs input[type="date"]:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
            outline: none;
        }
        
        .year-inputs .range-divider {
            color: #6b7280;
            font-weight: 600;
            font-size: 1.125rem;
            padding: 0 0.25rem;
        }
        
        .quick-filters {
            display: flex;
            gap: 0.625rem;
            margin-top: 0.75rem;
            flex-wrap: wrap;
        }
        
        .quick-filter-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #e5e7eb;
            background: #ffffff;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            transition: border-color 0.2s ease, background-color 0.2s ease;
            color: #111827;
        }
        
        .quick-filter-btn:hover {
            border-color: var(--secondary-color);
            background: #f9fafb;
        }
        
        .quick-filter-btn.active {
            background: var(--secondary-color);
            color: #ffffff;
            border-color: var(--secondary-color);
            font-weight: 600;
        }
    </style>
</head>
<body>

    <!-- Include Sidebar Component -->
    <?php include 'admin_sidebar.php'; ?>
    
    <!-- Include Navbar Component -->
    <?php include 'admin_navbar.php'; ?>

    <!-- Main Content Area -->
    <div class="admin-content">

        <!-- Action Bar -->
        <div class="action-bar">
            <h2 class="action-title">
                <i class="bi bi-list-ul"></i>
                All Listings
            </h2>
            <div class="action-buttons">
                <button type="button" class="btn btn-modern btn-secondary-modern" id="openFilterSidebar">
                    <i class="bi bi-funnel"></i>
                    Filter Properties
                    <span class="filter-count-badge" id="filterCountBadge" style="display:none;">0</span>
                </button>
                <a href="add_property.php" class="btn btn-modern btn-primary-modern">
                    <i class="bi bi-plus-circle"></i>
                    Add New Property
                </a>
            </div>
        </div>
        
        <!-- Property Tabs -->
        <div class="property-tabs">
            <ul class="nav nav-tabs" id="propertyStatusTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all-content" type="button" role="tab">
                        <i class="bi bi-list-ul me-2"></i>
                        All Listings
                        <span class="tab-badge badge-approved"><?php echo count($properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-content" type="button" role="tab">
                        <i class="bi bi-clock-history me-2"></i>
                        Pending Review
                        <span class="tab-badge badge-pending"><?php echo count($pending_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pending-sold-tab" data-bs-toggle="tab" data-bs-target="#pending-sold-content" type="button" role="tab">
                        <i class="bi bi-hourglass-split me-2"></i>
                        Pending Sold
                        <span class="tab-badge badge-pending"><?php echo count($pending_sold_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved-content" type="button" role="tab">
                        <i class="bi bi-check-circle me-2"></i>
                        Approved
                        <span class="tab-badge badge-approved"><?php echo count($approved_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected-content" type="button" role="tab">
                        <i class="bi bi-x-circle me-2"></i>
                        Rejected
                        <span class="tab-badge badge-rejected"><?php echo count($rejected_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sold-tab" data-bs-toggle="tab" data-bs-target="#sold-content" type="button" role="tab">
                        <i class="bi bi-tag-fill me-2"></i>
                        Sold
                        <span class="tab-badge" style="background: linear-gradient(135deg,#6c757d,#495057); color:#fff"><?php echo count($sold_properties); ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="propertyStatusTabsContent">
                <!-- All Properties -->
                <div class="tab-pane fade show active" id="all-content" role="tabpanel">
                    <div class="properties-grid" id="all-grid"></div>
                </div>
                <!-- Pending Properties -->
                <div class="tab-pane fade" id="pending-content" role="tabpanel">
                    <div class="properties-grid" id="pending-grid"></div>
                </div>

                <!-- Pending Sold Properties -->
                <div class="tab-pane fade" id="pending-sold-content" role="tabpanel">
                    <div class="properties-grid" id="pending-sold-grid"></div>
                </div>

                <!-- Approved Properties -->
                <div class="tab-pane fade" id="approved-content" role="tabpanel">
                    <div class="properties-grid" id="approved-grid"></div>
                </div>

                <!-- Rejected Properties -->
                <div class="tab-pane fade" id="rejected-content" role="tabpanel">
                    <div class="properties-grid" id="rejected-grid"></div>
                </div>
                <!-- Sold Properties -->
                <div class="tab-pane fade" id="sold-content" role="tabpanel">
                    <div class="properties-grid" id="sold-grid"></div>
                </div>
            </div>

            <!-- Hidden container with all property cards -->
            <div id="all-property-cards" style="display: none;">
                <?php foreach ($properties as $property): ?>
                    <div id="property-<?php echo $property['property_ID']; ?>" 
                         class="property-card-wrapper" 
                         data-approval-status="<?php echo htmlspecialchars($property['approval_status'] ?? ''); ?>" 
                         data-status="<?php echo htmlspecialchars(strtolower($property['Status'] ?? '')); ?>" 
                         data-property-id="<?php echo $property['property_ID']; ?>">
                        <?php include 'admin_property_card_template.php'; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Filter Sidebar -->
    <div class="filter-sidebar" id="filterSidebar">
        <div class="filter-sidebar-overlay" id="filterOverlay"></div>
        <div class="filter-sidebar-content">
            <div class="filter-header">
                <h4>
                    <i class="bi bi-funnel me-2"></i>Advanced Filters
                </h4>
                <button class="btn-close-filter" id="closeFilterBtn">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            
            <div class="filter-body">
                <!-- Search Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-search"></i>
                        Search
                    </div>
                    <div class="filter-search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search by address, city, or description...">
                    </div>
                </div>

                <!-- Price Range Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-currency-dollar"></i>
                        Price Range
                    </div>
                    <div class="price-slider-container">
                        <div class="price-slider-track">
                            <div class="price-slider-range" id="priceSliderRange"></div>
                        </div>
                        <input type="range" id="priceMinSlider" class="price-range-slider" min="0" max="50000000" value="0" step="100000">
                        <input type="range" id="priceMaxSlider" class="price-range-slider" min="0" max="50000000" value="50000000" step="100000">
                    </div>
                    <div class="price-range-inputs">
                        <div class="price-input">
                            <span class="currency-symbol">₱</span>
                            <input type="text" id="priceMin" class="form-control" placeholder="Min" readonly>
                        </div>
                        <span class="range-divider">—</span>
                        <div class="price-input">
                            <span class="currency-symbol">₱</span>
                            <input type="text" id="priceMax" class="form-control" placeholder="Max" readonly>
                        </div>
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-price-range="0-5000000">Under 5M</button>
                        <button class="quick-filter-btn" data-price-range="5000000-15000000">5M - 15M</button>
                        <button class="quick-filter-btn" data-price-range="15000000-30000000">15M - 30M</button>
                        <button class="quick-filter-btn" data-price-range="30000000-999999999">30M+</button>
                    </div>
                </div>

                <!-- Property Type Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-house-door"></i>
                        Property Type
                    </div>
                    <div class="filter-chips">
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="Single-Family Home" checked>
                            <span>Single-Family</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="Condominium" checked>
                            <span>Condo</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="Multi-Family" checked>
                            <span>Multi-Family</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="House" checked>
                            <span>House</span>
                        </label>
                    </div>
                </div>

                <!-- Bedrooms & Bathrooms Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-door-open"></i>
                        Bedrooms & Bathrooms
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">Bedrooms</label>
                            <select id="bedroomsFilter" class="filter-select">
                                <option value="">Any</option>
                                <option value="1">1+</option>
                                <option value="2">2+</option>
                                <option value="3">3+</option>
                                <option value="4">4+</option>
                                <option value="5">5+</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">Bathrooms</label>
                            <select id="bathroomsFilter" class="filter-select">
                                <option value="">Any</option>
                                <option value="1">1+</option>
                                <option value="2">2+</option>
                                <option value="3">3+</option>
                                <option value="4">4+</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Square Footage Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-rulers"></i>
                        Square Footage
                    </div>
                    <div class="price-range-inputs">
                        <div class="price-input">
                            <input type="number" id="sqftMin" class="form-control" placeholder="Min sq ft" min="0" step="100">
                        </div>
                        <span class="range-divider">—</span>
                        <div class="price-input">
                            <input type="number" id="sqftMax" class="form-control" placeholder="Max sq ft" min="0" step="100">
                        </div>
                    </div>
                </div>

                <!-- Year Built Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-calendar-event"></i>
                        Year Built
                    </div>
                    <div class="year-inputs">
                        <input type="number" id="yearMin" class="form-control" placeholder="From" min="1900" max="2025" step="1">
                        <span class="range-divider">—</span>
                        <input type="number" id="yearMax" class="form-control" placeholder="To" min="1900" max="2025" step="1">
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-year-range="2020-2025">New (2020+)</button>
                        <button class="quick-filter-btn" data-year-range="2010-2019">Recent (2010-2019)</button>
                        <button class="quick-filter-btn" data-year-range="1990-2009">Established</button>
                    </div>
                </div>

                <!-- Location Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-geo-alt"></i>
                        Location
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">City</label>
                            <select id="cityFilter" class="filter-select">
                                <option value="">All Cities</option>
                                <option value="Cagayan de Oro">Cagayan de Oro</option>
                                <option value="Manolo Fortich">Manolo Fortich</option>
                                <option value="Manolo Fortichh">Manolo Fortichh</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">County</label>
                            <select id="countyFilter" class="filter-select">
                                <option value="">All Counties</option>
                                <option value="Bukidnon">Bukidnon</option>
                                <option value="Misamis Oriental">Misamis Oriental</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Listing Status Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-tags"></i>
                        Listing Status
                    </div>
                    <div class="filter-chips">
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="For Sale" checked>
                            <span>For Sale</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="For Rent" checked>
                            <span>For Rent</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="Pending Sold" checked>
                            <span>Pending Sold</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="Sold" checked>
                            <span>Sold</span>
                        </label>
                    </div>
                </div>

                <!-- Approval Status Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-shield-check"></i>
                        Approval Status
                    </div>
                    <div class="filter-chips">
                        <label class="filter-chip">
                            <input type="checkbox" class="approval-filter" value="pending" checked>
                            <span>Pending</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="approval-filter" value="approved" checked>
                            <span>Approved</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="approval-filter" value="rejected" checked>
                            <span>Rejected</span>
                        </label>
                    </div>
                </div>

                <!-- Parking Type Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-car-front"></i>
                        Parking
                    </div>
                    <select id="parkingFilter" class="filter-select">
                        <option value="">Any Parking</option>
                        <option value="1-Car Garage">1-Car Garage</option>
                        <option value="2-Car Garage">2-Car Garage</option>
                        <option value="3-Car Garage">3-Car Garage</option>
                        <option value="Assigned Parking Space">Assigned Parking</option>
                        <option value="Private lot">Private Lot</option>
                        <option value="Garage">Garage</option>
                    </select>
                </div>

                <!-- Listing Date Range Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-calendar-range"></i>
                        Listing Date
                    </div>
                    <div class="year-inputs">
                        <input type="date" id="listingDateFrom" class="form-control">
                        <span class="range-divider">—</span>
                        <input type="date" id="listingDateTo" class="form-control">
                    </div>
                </div>

                <!-- Results Summary -->
                <div class="filter-results-summary">
                    <i class="bi bi-check-circle-fill"></i>
                    <div class="filter-results-text">
                        <div class="filter-results-count" id="filteredCount">0</div>
                        <div class="filter-results-label">Properties Match Your Criteria</div>
                    </div>
                </div>
            </div>

            <div class="filter-footer">
                <button class="btn btn-outline-secondary" id="clearFiltersBtn">
                    <i class="bi bi-arrow-clockwise me-2"></i>Reset
                </button>
                <button class="btn btn-primary" id="applyFiltersBtn">
                    <i class="bi bi-check2 me-2"></i>Apply Filters
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Embed properties data for client-side filtering
        const allProperties = <?php echo json_encode(array_map(function($p){
            // expose necessary fields to client for admin card display
            return [
                'property_ID' => $p['property_ID'] ?? null,
                'StreetAddress' => $p['StreetAddress'] ?? '',
                'City' => $p['City'] ?? '',
                'State' => $p['State'] ?? '',
                'ZIP' => $p['ZIP'] ?? '',
                'ListingPrice' => isset($p['ListingPrice']) ? (float)$p['ListingPrice'] : 0,
                'ListingDate' => $p['ListingDate'] ?? null,
                'approval_status' => $p['approval_status'] ?? '',
                'Status' => $p['Status'] ?? '', // Listing status: For Sale/For Rent/Sold
                'PhotoURL' => $p['PhotoURL'] ?? '',
                'PropertyType' => $p['PropertyType'] ?? '',
                'Bedrooms' => $p['Bedrooms'] ?? 0,
                'Bathrooms' => $p['Bathrooms'] ?? 0,
                'SquareFootage' => $p['SquareFootage'] ?? 0,
                'YearBuilt' => $p['YearBuilt'] ?? '',
                'ViewsCount' => $p['ViewsCount'] ?? 0,
                'Likes' => $p['Likes'] ?? 0,
            ];
        }, $properties)); ?>;

        // Determine price bounds
        const prices = allProperties.map(p => p.ListingPrice || 0);
        const PRICE_MIN = Math.min(...prices, 0);
        const PRICE_MAX = Math.max(...prices, 100000000);

    document.addEventListener('DOMContentLoaded', () => {
            // initialize price sliders limits
            const minEl = document.getElementById('priceMin');
            const maxEl = document.getElementById('priceMax');
            const minLabel = document.getElementById('priceMinLabel');
            const maxLabel = document.getElementById('priceMaxLabel');

            if (minEl && maxEl) {
                // initialize numeric slider bounds using the computed PRICE_MIN/PRICE_MAX
                const minSlider = document.getElementById('priceMinSlider');
                const maxSlider = document.getElementById('priceMaxSlider');
                if (minSlider && maxSlider) {
                    minSlider.min = PRICE_MIN;
                    minSlider.max = PRICE_MAX;
                    maxSlider.min = PRICE_MIN;
                    maxSlider.max = PRICE_MAX;
                    minSlider.value = PRICE_MIN;
                    maxSlider.value = PRICE_MAX;
                }

                // show formatted defaults in text inputs
                minEl.value = numberWithCommas(PRICE_MIN);
                maxEl.value = (PRICE_MAX >= 100000000 ? numberWithCommas(PRICE_MAX) + '+' : numberWithCommas(PRICE_MAX));
                if (minLabel) minLabel.textContent = 'Min: ' + numberWithCommas(PRICE_MIN);
                if (maxLabel) maxLabel.textContent = 'Max: ' + (PRICE_MAX >= 100000000 ? numberWithCommas(PRICE_MAX) + '+' : numberWithCommas(PRICE_MAX));
            }

            // wire other filters
            ['searchText', 'dateFrom', 'dateTo'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', applyFilters);
            });

            document.querySelectorAll('.status-filter').forEach(cb => cb.addEventListener('change', applyFilters));

            // initial render
            applyFilters();
        });

        function numberWithCommas(x) {
            if (x === null || x === undefined) return '';
            return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        function applyFilters() {
            const q = (document.getElementById('searchText')?.value || '').toLowerCase();
            // Parse formatted price inputs (they include commas and may have a trailing '+')
            const rawMin = document.getElementById('priceMin')?.value || '';
            const rawMax = document.getElementById('priceMax')?.value || '';
            const parseFormattedPrice = (s, fallback) => {
                if (!s) return fallback;
                // if it contains a + (e.g. "30,000,000+"), treat as upper bound (use PRICE_MAX)
                if (String(s).includes('+')) return PRICE_MAX;
                // strip non-numeric characters (commas, currency symbols)
                const n = Number(String(s).replace(/[^0-9.-]+/g, ''));
                return isNaN(n) ? fallback : n;
            };
            const minV = parseFormattedPrice(rawMin, PRICE_MIN);
            const maxV = parseFormattedPrice(rawMax, PRICE_MAX);
            const dateFrom = document.getElementById('dateFrom')?.value || null;
            const dateTo = document.getElementById('dateTo')?.value || null;
            // Determine selected LISTING statuses (For Sale / For Rent / Pending Sold / Sold)
            // If none selected, treat as 'All' (no status filter)
            const statusCheckedEls = Array.from(document.querySelectorAll('.status-filter:checked'));
            const hasStatusFilter = statusCheckedEls.length > 0;
            const statusCheckedLower = new Set(statusCheckedEls.map(i => String(i.value || '').toLowerCase()));

            const filtered = allProperties.filter(p => {
                // Listing status filter (uses p.Status values: For Sale / For Rent / Pending Sold / Sold)
                if (hasStatusFilter) {
                    const statusLower = String(p.Status || '').toLowerCase();
                    // If property has a known listing status, require it to be in selected set; otherwise don't filter it out.
                    if (statusLower && !statusCheckedLower.has(statusLower)) return false;
                }

                // price
                const price = Number(p.ListingPrice || 0);
                if (price < Math.min(minV, maxV) || price > Math.max(minV, maxV)) return false;
                // date
                if (dateFrom) {
                    if (!p.ListingDate) return false;
                    if (new Date(p.ListingDate) < new Date(dateFrom)) return false;
                }
                if (dateTo) {
                    if (!p.ListingDate) return false;
                    // include end date
                    const d = new Date(p.ListingDate);
                    const to = new Date(dateTo);
                    to.setHours(23,59,59,999);
                    if (d > to) return false;
                }
                // text search
                if (q) {
                    const hay = ((p.StreetAddress||'') + ' ' + (p.City||'') + ' ' + (p.State||'') + ' ' + (p.PropertyType||'')).toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                return true;
            });

            // render counts
            const summary = document.getElementById('filterResultsSummary');
            if (summary) {
                const byStatus = filtered.reduce((acc, cur) => { acc[cur.approval_status] = (acc[cur.approval_status]||0)+1; return acc; }, {});
                const pendingSoldCount = filtered.filter(p => (p.Status||'').toLowerCase() === 'pending sold').length;
                const soldCount = filtered.filter(p => (p.Status||'').toLowerCase() === 'sold').length;
                summary.innerHTML = `<strong>${filtered.length}</strong> result(s) — Pending: ${byStatus['pending']||0}, Pending Sold: ${pendingSoldCount}, Approved: ${byStatus['approved']||0}, Rejected: ${byStatus['rejected']||0}, Sold: ${soldCount}`;
            }

            renderGrids(filtered);
        }

        function renderGrids(filtered) {
            // Get all pre-rendered card wrappers
            const allCards = Array.from(document.querySelectorAll('#all-property-cards .property-card-wrapper'));
            
            // Create a map for quick lookup
            const cardMap = new Map();
            allCards.forEach(card => {
                const propertyId = card.getAttribute('data-property-id');
                cardMap.set(propertyId, card);
            });

            const ensureGrid = (paneSelector) => {
                const pane = document.querySelector(paneSelector);
                if (!pane) return null;
                // remove any existing empty-state
                const existingEmpty = pane.querySelector('.empty-state');
                if (existingEmpty) existingEmpty.remove();
                let grid = pane.querySelector('.properties-grid');
                if (!grid) {
                    grid = document.createElement('div');
                    grid.className = 'properties-grid';
                    pane.appendChild(grid);
                }
                return grid;
            };

            const allContainer = ensureGrid('#all-content');
            const pendingContainer = ensureGrid('#pending-content');
            const approvedContainer = ensureGrid('#approved-content');
            const rejectedContainer = ensureGrid('#rejected-content');
            const soldContainer = ensureGrid('#sold-content');
            const pendingSoldContainer = ensureGrid('#pending-sold-content');

            // Group by approval_status for pending/approved/rejected (exclude sold and pending sold listings)
            const groupByApproval = (status) => filtered.filter(p => {
                const sl = (p.Status||'').toLowerCase();
                return (p.approval_status||'') === status && sl !== 'sold' && sl !== 'pending sold';
            });
            // Separate Pending Sold and Sold
            const pendingSoldItems = filtered.filter(p => (p.Status||'').toLowerCase() === 'pending sold');
            const soldItems = filtered.filter(p => (p.Status||'').toLowerCase() === 'sold');

            const fill = (container, items) => {
                if (!container) return;
                // Clear existing content
                container.innerHTML = '';
                if (!items.length) {
                    // Render the empty state centered vertically and horizontally
                    container.innerHTML = `<div class="empty-state" style="max-width:720px;width:100%;margin:0 1rem;">
                        <i class="bi bi-search empty-state-icon"></i>
                        <h4>No matching properties</h4>
                        <p class="text-muted">Try adjusting your filters.</p>
                    </div>`;
                    // Use flex layout to center the message within the pane
                    container.style.display = 'flex';
                    container.style.justifyContent = 'center';
                    container.style.alignItems = 'center';
                    container.style.minHeight = '220px';
                    // Clear grid-specific properties if previously set
                    container.style.gridTemplateColumns = '';
                    container.style.gap = '';
                    return;
                }
                // Append cloned cards
                items.forEach(item => {
                    const card = cardMap.get(item.property_ID.toString());
                    if (card) {
                        const clonedCard = card.cloneNode(true);
                        container.appendChild(clonedCard);
                    }
                });
                // Restore grid layout
                container.style.display = 'grid';
                container.style.gridTemplateColumns = 'repeat(auto-fill, minmax(380px,1fr))';
                container.style.gap = '1.5rem';
                // Clear any flex centering styles
                container.style.justifyContent = '';
                container.style.alignItems = '';
                container.style.minHeight = '';
            };

            fill(allContainer, filtered);
            fill(pendingContainer, groupByApproval('pending'));
            fill(pendingSoldContainer, pendingSoldItems);
            fill(approvedContainer, groupByApproval('approved'));
            fill(rejectedContainer, groupByApproval('rejected'));
            fill(soldContainer, soldItems);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"'`]/g, function (s) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;","`":"&#x60;"})[s]; });
        }
        // Simple tab functionality enhancement
        document.addEventListener('DOMContentLoaded', function() {
            // Add smooth transitions to tab content
            const tabTriggerList = document.querySelectorAll('#propertyStatusTabs button[data-bs-toggle="tab"]');
            tabTriggerList.forEach(tabTrigger => {
                tabTrigger.addEventListener('shown.bs.tab', function(event) {
                    const targetContent = document.querySelector(event.target.getAttribute('data-bs-target'));
                    if (targetContent) {
                        targetContent.style.opacity = '0';
                        setTimeout(() => {
                            targetContent.style.transition = 'opacity 0.3s ease';
                            targetContent.style.opacity = '1';
                        }, 50);
                    }
                });
            });
        });
    </script>
    <script>
        // ===== COMPREHENSIVE FILTER SIDEBAR INTEGRATION =====
        // This integrates with the existing applyFilters and renderGrids functions
        
        let comprehensiveFilters = {
            search: '',
            priceMin: 0,
            priceMax: Infinity,
            propertyTypes: new Set(['Single-Family Home', 'Condominium', 'Multi-Family', 'House']),
            bedrooms: null,
            bathrooms: null,
            sqftMin: null,
            sqftMax: null,
            yearMin: null,
            yearMax: null,
            city: '',
            county: '',
            statuses: new Set(['For Sale', 'For Rent', 'Pending Sold', 'Sold']),
            approvalStatuses: new Set(['pending', 'approved', 'rejected']),
            parking: ''
        };

        // Open/Close Filter Sidebar
        document.getElementById('openFilterSidebar')?.addEventListener('click', () => {
            document.getElementById('filterSidebar').classList.add('active');
        });

        document.getElementById('closeFilterBtn')?.addEventListener('click', () => {
            document.getElementById('filterSidebar').classList.remove('active');
        });

        document.getElementById('filterOverlay')?.addEventListener('click', () => {
            document.getElementById('filterSidebar').classList.remove('active');
        });

        document.getElementById('applyFiltersBtn')?.addEventListener('click', () => {
            document.getElementById('filterSidebar').classList.remove('active');
        });

        // Integrate comprehensive filters with existing system
        function applyComprehensiveFilters() {
            const filtered = allProperties.filter(property => {
                // Search
                if (comprehensiveFilters.search) {
                    const text = `${property.StreetAddress} ${property.City} ${property.ListingDescription || ''}`.toLowerCase();
                    if (!text.includes(comprehensiveFilters.search)) return false;
                }
                
                // Price
                const price = property.ListingPrice || 0;
                if (price < comprehensiveFilters.priceMin || (comprehensiveFilters.priceMax !== Infinity && price > comprehensiveFilters.priceMax)) {
                    return false;
                }
                
                // Property Type
                if (!comprehensiveFilters.propertyTypes.has(property.PropertyType)) return false;
                
                // Bedrooms
                if (comprehensiveFilters.bedrooms && property.Bedrooms < comprehensiveFilters.bedrooms) return false;
                
                // Bathrooms
                if (comprehensiveFilters.bathrooms && property.Bathrooms < comprehensiveFilters.bathrooms) return false;
                
                // Square Footage
                if (comprehensiveFilters.sqftMin && property.SquareFootage < comprehensiveFilters.sqftMin) return false;
                if (comprehensiveFilters.sqftMax && property.SquareFootage > comprehensiveFilters.sqftMax) return false;
                
                // Year Built
                if (comprehensiveFilters.yearMin && property.YearBuilt < comprehensiveFilters.yearMin) return false;
                if (comprehensiveFilters.yearMax && property.YearBuilt > comprehensiveFilters.yearMax) return false;
                
                // Location
                if (comprehensiveFilters.city && property.City !== comprehensiveFilters.city) return false;
                if (comprehensiveFilters.county && property.County !== comprehensiveFilters.county) return false;
                
                // Status
                if (!comprehensiveFilters.statuses.has(property.Status)) return false;
                
                // Approval
                if (!comprehensiveFilters.approvalStatuses.has(property.approval_status)) return false;
                
                // Parking
                if (comprehensiveFilters.parking && property.ParkingType !== comprehensiveFilters.parking) return false;
                
                // Listing Date
                if (document.getElementById('listingDateFrom')?.value && property.ListingDate < document.getElementById('listingDateFrom').value) return false;
                if (document.getElementById('listingDateTo')?.value && property.ListingDate > document.getElementById('listingDateTo').value) return false;
                
                return true;
            });

            document.getElementById('filteredCount').textContent = filtered.length;
            
            const activeCount = countComprehensiveFilters();
            const badge = document.getElementById('filterCountBadge');
            if (badge) {
                if (activeCount > 0) {
                    badge.textContent = activeCount;
                    badge.style.display = 'inline-flex';
                } else {
                    badge.style.display = 'none';
                }
            }

            renderGrids(filtered);
        }

        function countComprehensiveFilters() {
            let count = 0;
            if (comprehensiveFilters.search) count++;
            if (comprehensiveFilters.priceMin > 0 || comprehensiveFilters.priceMax < Infinity) count++;
            if (comprehensiveFilters.propertyTypes.size < 4) count++;
            if (comprehensiveFilters.bedrooms) count++;
            if (comprehensiveFilters.bathrooms) count++;
            if (comprehensiveFilters.sqftMin || comprehensiveFilters.sqftMax) count++;
            if (comprehensiveFilters.yearMin || comprehensiveFilters.yearMax) count++;
            if (comprehensiveFilters.city) count++;
            if (comprehensiveFilters.county) count++;
            if (comprehensiveFilters.statuses.size < 4) count++;
            if (comprehensiveFilters.approvalStatuses.size < 3) count++;
            if (comprehensiveFilters.parking) count++;
            if (document.getElementById('listingDateFrom')?.value || document.getElementById('listingDateTo')?.value) count++;
            return count;
        }

        // Wire up all filter inputs
        document.getElementById('searchInput')?.addEventListener('input', (e) => {
            comprehensiveFilters.search = e.target.value.toLowerCase();
            applyComprehensiveFilters();
        });

        // price text inputs are display-only (formatted). Parsing/formating done via helpers when sliders change
        function parsePriceInput(str) {
            if (!str) return 0;
            return Number(String(str).replace(/[^0-9.-]+/g, '')) || 0;
        }

        function setPriceInputs(minVal, maxVal) {
            const minInput = document.getElementById('priceMin');
            const maxInput = document.getElementById('priceMax');
            if (minInput) minInput.value = numberWithCommas(minVal);
            if (maxInput) maxInput.value = (maxVal >= 100000000 ? numberWithCommas(maxVal) + '+' : numberWithCommas(maxVal));
        }

        // Draggable Price Range Sliders
        const priceMinSlider = document.getElementById('priceMinSlider');
        const priceMaxSlider = document.getElementById('priceMaxSlider');
        const priceMinInput = document.getElementById('priceMin');
        const priceMaxInput = document.getElementById('priceMax');
        const priceSliderRange = document.getElementById('priceSliderRange');

        function updatePriceSliderRange() {
            if (!priceMinSlider || !priceMaxSlider || !priceSliderRange) return;
            
            const minVal = parseInt(priceMinSlider.value);
            const maxVal = parseInt(priceMaxSlider.value);
            const minPercent = (minVal / priceMinSlider.max) * 100;
            const maxPercent = (maxVal / priceMaxSlider.max) * 100;
            
            priceSliderRange.style.left = minPercent + '%';
            priceSliderRange.style.width = (maxPercent - minPercent) + '%';
        }

        if (priceMinSlider) {
            priceMinSlider.addEventListener('input', (e) => {
                let minVal = parseInt(e.target.value);
                let maxVal = parseInt(priceMaxSlider.value);

                // prevent crossing: keep a minimum gap of 500,000
                const GAP = 500000;
                if (minVal > maxVal - GAP) {
                    minVal = maxVal - GAP;
                    e.target.value = minVal;
                }

                // update visible formatted inputs
                setPriceInputs(minVal, maxVal);

                // update internal values
                comprehensiveFilters.priceMin = minVal;
                // if maxVal equals slider max, treat as Infinity in the filter
                comprehensiveFilters.priceMax = (maxVal >= Number(priceMaxSlider.max)) ? Infinity : maxVal;

                updatePriceSliderRange();
                applyComprehensiveFilters();
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

                setPriceInputs(minVal, maxVal);

                comprehensiveFilters.priceMin = minVal;
                comprehensiveFilters.priceMax = (maxVal >= Number(priceMaxSlider.max)) ? Infinity : maxVal;

                updatePriceSliderRange();
                applyComprehensiveFilters();
            });
        }

        // Initialize slider range
        updatePriceSliderRange();

        document.querySelectorAll('.property-type-filter').forEach(cb => {
            cb.addEventListener('change', () => {
                comprehensiveFilters.propertyTypes = new Set(Array.from(document.querySelectorAll('.property-type-filter:checked')).map(c => c.value));
                updateChipStates();
                applyComprehensiveFilters();
            });
        });

        document.getElementById('bedroomsFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.bedrooms = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('bathroomsFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.bathrooms = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('sqftMin')?.addEventListener('input', (e) => {
            comprehensiveFilters.sqftMin = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('sqftMax')?.addEventListener('input', (e) => {
            comprehensiveFilters.sqftMax = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('yearMin')?.addEventListener('input', (e) => {
            comprehensiveFilters.yearMin = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('yearMax')?.addEventListener('input', (e) => {
            comprehensiveFilters.yearMax = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('cityFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.city = e.target.value;
            applyComprehensiveFilters();
        });

        document.getElementById('countyFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.county = e.target.value;
            applyComprehensiveFilters();
        });

        document.querySelectorAll('.status-filter').forEach(cb => {
            cb.addEventListener('change', () => {
                comprehensiveFilters.statuses = new Set(Array.from(document.querySelectorAll('.status-filter:checked')).map(c => c.value));
                updateChipStates();
                applyComprehensiveFilters();
            });
        });

        document.querySelectorAll('.approval-filter').forEach(cb => {
            cb.addEventListener('change', () => {
                comprehensiveFilters.approvalStatuses = new Set(Array.from(document.querySelectorAll('.approval-filter:checked')).map(c => c.value));
                updateChipStates();
                applyComprehensiveFilters();
            });
        });

        document.getElementById('parkingFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.parking = e.target.value;
            applyComprehensiveFilters();
        });

        document.getElementById('listingDateFrom')?.addEventListener('change', applyComprehensiveFilters);
        document.getElementById('listingDateTo')?.addEventListener('change', applyComprehensiveFilters);

        // Quick filters
        document.querySelectorAll('[data-price-range]').forEach(btn => {
            btn.addEventListener('click', () => {
                const [min, max] = btn.dataset.priceRange.split('-');
                const minVal = Number(min);
                const maxVal = Number(max);

                // set sliders
                const minSlider = document.getElementById('priceMinSlider');
                const maxSlider = document.getElementById('priceMaxSlider');
                if (minSlider && maxSlider) {
                    minSlider.value = minVal;
                    maxSlider.value = maxVal;
                }

                // update display
                setPriceInputs(minVal, maxVal);

                comprehensiveFilters.priceMin = minVal;
                comprehensiveFilters.priceMax = (maxVal >= Number(maxSlider.max)) ? Infinity : maxVal;

                updatePriceSliderRange();

                document.querySelectorAll('[data-price-range]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                applyComprehensiveFilters();
            });
        });

        document.querySelectorAll('[data-year-range]').forEach(btn => {
            btn.addEventListener('click', () => {
                const [min, max] = btn.dataset.yearRange.split('-');
                document.getElementById('yearMin').value = min;
                document.getElementById('yearMax').value = max;
                comprehensiveFilters.yearMin = Number(min);
                comprehensiveFilters.yearMax = Number(max);
                document.querySelectorAll('[data-year-range]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                applyComprehensiveFilters();
            });
        });

        // Clear filters
        document.getElementById('clearFiltersBtn')?.addEventListener('click', () => {
            document.querySelectorAll('#filterSidebar input[type="text"], #filterSidebar input[type="number"], #filterSidebar input[type="date"], #filterSidebar select').forEach(el => el.value = '');
            document.querySelectorAll('#filterSidebar input[type="checkbox"]').forEach(cb => cb.checked = true);
            document.querySelectorAll('.quick-filter-btn').forEach(btn => btn.classList.remove('active'));
            
            // Reset price sliders
            if (document.getElementById('priceMinSlider')) {
                const minSlider = document.getElementById('priceMinSlider');
                const maxSlider = document.getElementById('priceMaxSlider');
                if (minSlider && maxSlider) {
                    minSlider.value = PRICE_MIN;
                    maxSlider.value = PRICE_MAX;
                }
                setPriceInputs(PRICE_MIN, PRICE_MAX);
                updatePriceSliderRange();
            }
            
            comprehensiveFilters = {
                search: '',
                priceMin: 0,
                priceMax: Infinity,
                propertyTypes: new Set(['Single-Family Home', 'Condominium', 'Multi-Family', 'House']),
                bedrooms: null,
                bathrooms: null,
                sqftMin: null,
                sqftMax: null,
                yearMin: null,
                yearMax: null,
                city: '',
                county: '',
                statuses: new Set(['For Sale', 'For Rent', 'Pending Sold', 'Sold']),
                approvalStatuses: new Set(['pending', 'approved', 'rejected']),
                parking: ''
            };
            
            updateChipStates();
            applyComprehensiveFilters();
        });

        function updateChipStates() {
            document.querySelectorAll('.filter-chip').forEach(chip => {
                const checkbox = chip.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    chip.classList.toggle('active', checkbox.checked);
                }
            });
        }

        // Initialize
        setTimeout(() => {
            updateChipStates();
            document.getElementById('filteredCount').textContent = allProperties.length;
        }, 100);
    </script>

</body>
</html>