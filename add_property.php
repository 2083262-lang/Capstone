<?php
session_start();
include 'connection.php'; // Your database connection

// Check if the user is logged in and is either an admin or an agent
if (!isset($_SESSION['account_id']) || !in_array($_SESSION['user_role'], ['admin', 'agent'])) {
    header("Location: login.php");
    exit();
}

// Fetch all amenities for the checklist
$amenities_result = $conn->query("SELECT * FROM amenities ORDER BY amenity_name");

// Check for session messages
$message_type = $_SESSION['message']['type'] ?? '';
$message_text = $_SESSION['message']['text'] ?? '';
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Property - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
            --sidebar-width: 250px;
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --background-color: #f5f5f5;
            --card-bg-color: #ffffff;
            --border-color: #e0e0e0;
            --text-muted: #6c757d;
            --shadow-light: 0 1px 3px rgba(0,0,0,0.08);
            --shadow-medium: 0 2px 8px rgba(0,0,0,0.12);
            --shadow-heavy: 0 4px 16px rgba(0,0,0,0.15);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--background-color);
            color: var(--primary-color);
        }

        /* Main Content Styling */
        .main-content { 
            margin-left: var(--sidebar-width); 
            padding: 2rem; 
            background-color: var(--background-color);
            min-height: 100vh;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }

        /* Page Header */
        .page-header {
            background: white;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            border-radius: 12px;
            box-shadow: var(--shadow-light);
            border-left: 4px solid var(--secondary-color);
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-header h1 i {
            color: var(--secondary-color);
        }

        .page-header p {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin: 0;
        }

        /* Form Container */
        .form-container {
            background: transparent;
            margin-bottom: 2rem;
        }

        .form-header {
            display: none; /* Remove the old gradient header */
        }

        .form-body {
            padding: 0;
        }

        /* Form Sections */
        .form-section {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-light);
        }

        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .section-title i {
            color: var(--secondary-color);
            font-size: 1.125rem;
        }

        /* Form Controls */
        .form-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            display: block;
        }

        .form-label .required {
            color: #dc2626;
            margin-left: 0.25rem;
        }

        .form-label .optional {
            color: #f59e0b;
            font-weight: 500;
            margin-left: 0.25rem;
            font-size: 0.8125rem;
        }

        .form-control, .form-select {
            height: 42px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            padding: 0.5rem 0.875rem;
            font-size: 0.9375rem;
            background-color: #fff;
            transition: all 0.15s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
            outline: none;
        }

        .form-control:hover, .form-select:hover {
            border-color: #bc9e42;
        }

        .form-control::placeholder {
            color: #9ca3af;
        }

        .form-control.optional-field {
            background-color: #fffbf0;
        }

        /* Input Groups */
        .input-group {
            position: relative;
        }

        .input-group .form-control {
            /* padding-left: 2.75rem; REMOVED GLOBAL PADDING */
        }

        /* Textarea */
        textarea.form-control {
            resize: vertical;
            min-height: 120px;
            height: auto;
            border-radius: 8px !important;
            padding: 0.75rem 0.875rem;
        }

        /* ===== CUSTOM PROPERTY INPUT GROUP STYLES - ISOLATED ===== */
        
        /* Container for input with left icon */
        .property-input-group {
            position: relative;
            display: block; /* Not flex, to prevent layout shifts */
        }

        /* Input field inside property-input-group */
        .property-input-group .property-form-input {
            padding-left: 2.5rem !important; /* Space for left icon */
            padding-right: calc(1.5em + 0.75rem) !important; /* Space for validation icon on right */
            border-radius: 8px !important;
            height: 42px !important; /* Fixed height to prevent shifts */
            display: block;
            width: 100%;
        }

        /* Icon positioned on the left - LOCKED positioning */
        .property-input-group .property-input-icon {
            position: absolute !important;
            left: 0.875rem !important;
            top: 11px !important; /* Fixed pixel value for consistent centering (42px height / 2 = 21px - icon height/2) */
            display: inline-block !important;
            color: var(--text-muted) !important;
            pointer-events: none !important;
            z-index: 100 !important; /* High z-index to stay on top */
            line-height: 1 !important;
            font-size: 1rem !important;
        }

        /* Ensure validation state doesn't affect our icon or input */
        .property-input-group .property-form-input:invalid,
        .property-input-group .property-form-input.is-invalid,
        .was-validated .property-input-group .property-form-input:invalid {
            padding-left: 2.5rem !important; /* Keep left padding for our icon */
            padding-right: calc(1.5em + 0.75rem) !important; /* Keep right padding for validation icon */
            border-radius: 8px !important;
            height: 42px !important;
            background-position: right calc(0.375em + 0.1875rem) center !important; /* Validation icon on right */
        }

        /* Ensure icon stays in place even on validation error */
        .was-validated .property-input-group .property-input-icon,
        .property-input-group .property-form-input:invalid ~ .property-input-icon,
        .property-input-group .property-form-input.is-invalid ~ .property-input-icon {
            top: 11px !important;
            left: 0.875rem !important;
            position: absolute !important;
        }

        /* Legacy input-group-icon class support (for backwards compatibility) */
        .property-input-group .input-group-icon {
            position: absolute !important;
            left: 0.875rem !important;
            top: 11px !important;
            display: inline-block !important;
            color: var(--text-muted) !important;
            pointer-events: none !important;
            z-index: 100 !important;
            line-height: 1 !important;
        }

        /* ===== END CUSTOM PROPERTY INPUT GROUP STYLES ===== */
        
        /* Reset padding for inputs NOT in our custom group */
        .form-control:not(.property-form-input) {
            padding-left: 0.875rem;
        }

        /* Row spacing consistency */
        .form-section .row {
            margin-left: -0.5rem;
            margin-right: -0.5rem;
        }

        .form-section .row > [class*='col'] {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .form-section .g-3 {
            --bs-gutter-x: 1rem;
            --bs-gutter-y: 1rem;
        }

        /* Amenities Section */
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .amenities-container {
            max-height: 400px; /* Adjust height as needed */
            overflow-y: auto;
            padding-right: 0.5rem;
            margin-top: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            background-color: #fff;
        }

        /* Custom Scrollbar for Amenities */
        .amenities-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .amenities-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .amenities-container::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 4px;
        }
        
        .amenities-container::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }

        .amenities-search {
            position: relative;
            margin-bottom: 1rem;
        }

        .amenities-search .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .amenities-search input {
            padding-left: 2.5rem;
            border-radius: 8px !important; 
        }

        /* Make amenity cards more compact for the scrollable list */
        .form-check {
            background: #f9fafb;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            transition: all 0.15s ease;
            margin-bottom: 0;
            display: flex;
            align-items: center;
        }

        .form-check:hover {
            background: #fefcf3;
            border-color: var(--secondary-color);
        }

        .form-check-input {
            border-radius: 4px !important;
            margin-top: 0; /* Align checkbox vertically */
            margin-right: 0.5rem;
        }

        .form-check-input:checked {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .form-check-input:focus {
            box-shadow: 0 0 0 0.2rem rgba(188, 158, 66, 0.25);
        }

        .form-check-label {
            font-weight: 500;
            color: var(--primary-color);
            cursor: pointer;
            font-size: 0.9375rem;
        }

        /* Custom Property Form Inputs - Consistent Rounded Corners */
        .property-form-input {
            border-radius: 8px !important;
        }

        .property-form-select {
            border-radius: 8px !important;
        }

        .property-input-group .form-control {
            border-radius: 8px !important;
        }

        .property-input-group .input-group-text {
            border-radius: 8px !important;
        }

        /* Ensure textarea maintains rounded corners */
        .property-form-textarea {
            border-radius: 8px !important;
        }

        /* Image Upload Section */
        .image-upload-section {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            background: #fafafa;
            transition: all 0.15s ease;
            position: relative;
        }

        .image-upload-section:hover {
            border-color: var(--secondary-color);
            background: #fefcf3;
        }

        .image-upload-section.dragover {
            border-color: var(--secondary-color);
            background: rgba(188, 158, 66, 0.08);
        }

        .upload-icon {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }

        .upload-text {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.375rem;
        }

        .upload-subtext {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .file-input-wrapper {
            margin-top: 1rem;
        }

        .file-input {
            position: absolute;
            left: -9999px;
        }

        .file-input-button {
            background: linear-gradient(135deg, var(--secondary-color), #a08636);
            color: #fff;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: var(--shadow-light);
            font-size: 0.9375rem;
        }

        .file-input-button:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-medium);
        }

        /* Image Preview Grid */
        .image-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .image-preview-item {
            position: relative;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .preview-image {
            width: 100%;
            height: 110px;
            object-fit: cover;
        }

        .image-info {
            padding: 0.625rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
        }

        .remove-image {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: rgba(220, 38, 38, 0.9);
            color: #fff;
            border: none;
            border-radius: 6px;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .remove-image:hover {
            background: #dc2626;
        }

        /* Floor Upload Sections */
        .floor-upload-card {
            background: #fafafa;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }

        .floor-upload-card.error {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .floor-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .floor-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .floor-title i {
            color: var(--secondary-color);
        }

        .floor-badge {
            background: linear-gradient(135deg, var(--secondary-color), #a08636);
            color: white;
            padding: 0.375rem 0.875rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
        }

        .floor-upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            background: white;
            transition: all 0.15s ease;
            cursor: pointer;
        }

        .floor-upload-area:hover {
            border-color: var(--secondary-color);
            background: #fefcf3;
        }

        .floor-upload-area.has-files {
            border-style: solid;
            border-color: var(--secondary-color);
            background: rgba(188, 158, 66, 0.05);
        }

        .floor-upload-icon {
            font-size: 1.75rem;
            color: var(--secondary-color);
            margin-bottom: 0.625rem;
        }

        .floor-upload-text {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .floor-upload-subtext {
            font-size: 0.8125rem;
            color: var(--text-muted);
        }

        .floor-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .floor-preview-item {
            position: relative;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .floor-preview-image {
            width: 100%;
            height: 90px;
            object-fit: cover;
        }

        .floor-image-info {
            padding: 0.5rem;
            font-size: 0.7rem;
            color: var(--text-muted);
            background: #f9fafb;
            border-top: 1px solid var(--border-color);
        }

        .remove-floor-image {
            position: absolute;
            top: 0.375rem;
            right: 0.375rem;
            background: rgba(220, 38, 38, 0.9);
            color: white;
            border: none;
            border-radius: 6px;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 0.75rem;
        }

        .remove-floor-image:hover {
            background: #dc2626;
        }

        /* Submit Button */
        .submit-section {
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            text-align: center;
            margin-top: 1.5rem;
            box-shadow: var(--shadow-light);
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--secondary-color), #a08636);
            color: #fff;
            border: none;
            padding: 0.875rem 2.5rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.15s ease;
            box-shadow: var(--shadow-light);
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-medium);
        }

        .btn-cancel {
            background: #fff;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            padding: 0.875rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            margin-right: 1rem;
            transition: all 0.15s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-cancel:hover {
            border-color: var(--secondary-color);
            color: var(--secondary-color);
            background: #fefcf3;
        }

        /* Alert Messages */
        .alert {
            border-radius: 8px;
            border: 1px solid;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-light);
        }

        .alert-success {
            background: #f0fdf4;
            border-color: #86efac;
            color: #166534;
        }

        .alert-danger {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        /* Progress Indicator */
        .form-progress {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .progress-bar {
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--secondary-color), #a08636);
            width: 0%;
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-align: center;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .form-section {
                padding: 1.5rem;
            }
            
            .amenities-grid {
                grid-template-columns: 1fr;
            }
            
            .image-preview-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            }

            .page-header h1 {
                font-size: 1.5rem;
            }
        }

        /* Form text helper */
        .form-text {
            font-size: 0.8125rem;
            color: var(--text-muted);
            margin-top: 0.375rem;
        }

        /* Info message */
        .text-muted {
            color: var(--text-muted) !important;
        }

        .text-center {
            text-align: center;
        }
    </style>
</head>
<body>

    <!-- Include Sidebar Component -->
    <?php 
        // Ensure the Properties menu is highlighted when adding a property
        $active_page = 'property.php';
        include 'admin_sidebar.php'; 
    ?>
    
    <!-- Include Navbar Component -->
    <?php include 'admin_navbar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
    
        <!-- Form Progress Indicator -->
        <div class="form-progress">
            <div class="progress-bar">
                <div class="progress-fill" id="formProgress"></div>
            </div>
            <div class="progress-text" id="progressText">Complete the form below to add a new property</div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message_text): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
                <?php echo $message_text; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form action="save_property.php" method="POST" enctype="multipart/form-data" id="propertyForm">
                    
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-house"></i>
                            Basic Information
                        </h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="StreetAddress" class="form-label">
                                    Street Address <span class="required">*</span>
                                </label>
                                <div class="property-input-group">
                                    <i class="bi bi-geo-alt property-input-icon"></i>
                                    <input type="text" id="StreetAddress" name="StreetAddress" class="form-control property-form-input" 
                                           placeholder="Enter street address" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="City" class="form-label">
                                    City <span class="required">*</span>
                                </label>
                                <input type="text" id="City" name="City" class="form-control property-form-input" 
                                       placeholder="City name" required>
                            </div>
                            <div class="col-md-2">
                                <label for="State" class="form-label">
                                    State <span class="required">*</span>
                                </label>
                                <input type="text" id="State" name="State" maxlength="2" class="form-control property-form-input" 
                                       placeholder="PH" pattern="[A-Za-z]{2}" 
                                       title="Please enter a 2-character state abbreviation" required>
                            </div>
                            <div class="col-md-1">
                                <label for="ZIP" class="form-label">
                                    ZIP <span class="required">*</span>
                                </label>
                    <input type="text" id="ZIP" name="ZIP" class="form-control property-form-input" 
                        placeholder="ZIP code" pattern="\d{4}" maxlength="4" inputmode="numeric" title="Enter a 4-digit PH postal code" required>
                            </div>
                            <div class="col-md-6">
                                <label for="County" class="form-label">County <span class="required">*</span></label>
                                <input type="text" id="County" name="County" class="form-control property-form-input" 
                                       placeholder="County name" required>
                            </div>
                            <div class="col-md-3">
                                <label for="PropertyType" class="form-label">
                                    Property Type <span class="required">*</span>
                                </label>
                                <select id="PropertyType" name="PropertyType" class="form-select property-form-select" required>
                                    <option value="">Select Property Type</option>
                                    <option value="Single-Family Home">Single-Family Home</option>
                                    <option value="Condominium">Condominium</option>
                                    <option value="Townhouse">Townhouse</option>
                                    <option value="Multi-Family">Multi-Family</option>
                                    <option value="Land">Land</option>
                                    <option value="Commercial">Commercial</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="Status" class="form-label">
                                    Status <span class="required">*</span>
                                </label>
                                <select id="Status" name="Status" class="form-select property-form-select" required>
                                    <option value="">Select Status</option>
                                    <option value="For Sale">For Sale</option>
                                    <option value="For Rent">For Rent</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Property Details Section -->
                    <div class="form-section" id="propertyDetailsSection">
                        <h3 class="section-title">
                            <i class="bi bi-rulers"></i>
                            Property Details
                        </h3>

                        <!-- First row: five compact inputs (now equally expanded to maximize width) -->
                        <div class="row g-2 align-items-start">
                            <div class="col">
                                <div class="form-group">
                                    <label for="YearBuilt" class="form-label">Year Built <span class="required">*</span></label>
                                    <input type="number" id="YearBuilt" name="YearBuilt" class="form-control property-form-input" min="1800" max="<?php echo date("Y") + 5; ?>" placeholder="e.g., 2020" required>
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="NumberOfFloors" class="form-label">Floors <span class="required">*</span></label>
                                    <input type="number" id="NumberOfFloors" name="NumberOfFloors" class="form-control property-form-input" min="1" max="10" placeholder="1-10" value="1" required>
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="Bedrooms" class="form-label">Bedrooms <span class="required">*</span></label>
                                    <input type="number" id="Bedrooms" name="Bedrooms" class="form-control property-form-input" min="0" placeholder="e.g., 3" required>
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="Bathrooms" class="form-label">Bathrooms <span class="required">*</span></label>
                                    <input type="number" step="0.5" id="Bathrooms" name="Bathrooms" class="form-control property-form-input" min="0" placeholder="e.g., 2.5" required>
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="ListingDate" class="form-label">Listing Date <span class="required">*</span></label>
                                    <input type="date" id="ListingDate" name="ListingDate" class="form-control property-form-input" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Second row: four remaining wider inputs -->
                        <div class="row g-3 align-items-start mt-2">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="SquareFootage" class="form-label" id="SquareFootageLabel">Square Footage (ft²) <span class="required">*</span></label>
                                    <div class="property-input-group">
                                        <i class="bi bi-arrows-fullscreen property-input-icon"></i>
                                        <input type="number" id="SquareFootage" name="SquareFootage" class="form-control property-form-input" min="1" placeholder="e.g., 2500" required>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="LotSize" class="form-label" id="LotSizeLabel">Lot Size (acres) <span class="required">*</span></label>
                                    <input type="number" step="0.01" id="LotSize" name="LotSize" class="form-control property-form-input" min="0" placeholder="e.g., 0.25" required>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="ParkingType" class="form-label">Parking Type <span class="required">*</span></label>
                                    <input type="text" id="ParkingType" name="ParkingType" class="form-control property-form-input" placeholder="e.g., Garage, Driveway" required>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="ListingPrice" class="form-label" id="priceLabel">Listing Price <span class="required">*</span></label>
                                    <div class="property-input-group">
                                        <!-- Peso Icon (Text only, no Bootstrap Icon class) -->
                                        <span class="property-input-icon fw-bold" style="font-size: 1.1rem;">₱</span>
                                        <input type="number" step="0.01" id="ListingPrice" name="ListingPrice" class="form-control property-form-input" min="0.01" placeholder="e.g., 500,000" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Rental Details Section -->
                    <div class="form-section d-none" id="rentalDetailsSection">
                        <h3 class="section-title">
                            <i class="bi bi-key"></i>
                            Rental Details
                        </h3>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="SecurityDeposit" class="form-label">Security Deposit</label>
                                <div class="property-input-group">
                                    <i class="bi bi-shield-lock property-input-icon"></i>
                                    <input type="number" step="0.01" min="0" id="SecurityDeposit" name="SecurityDeposit" class="form-control property-form-input" placeholder="e.g., 50000">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="LeaseTermMonths" class="form-label">Lease Term (months)</label>
                                <select id="LeaseTermMonths" name="LeaseTermMonths" class="form-select property-form-select">
                                    <option value="">Select Lease Term</option>
                                    <option value="6">6 months</option>
                                    <option value="12">12 months</option>
                                    <option value="18">18 months</option>
                                    <option value="24">24 months</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="Furnishing" class="form-label">Furnishing</label>
                                <select id="Furnishing" name="Furnishing" class="form-select property-form-select">
                                    <option value="">Select Furnishing</option>
                                    <option value="Unfurnished">Unfurnished</option>
                                    <option value="Semi-Furnished">Semi-Furnished</option>
                                    <option value="Fully Furnished">Fully Furnished</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="AvailableFrom" class="form-label">Available From</label>
                                <input type="date" id="AvailableFrom" name="AvailableFrom" class="form-control property-form-input" min="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- MLS Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-database"></i>
                            MLS Information
                        </h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="Source" class="form-label">Source (MLS Name) <span class="required">*</span></label>
                                <input type="text" id="Source" name="Source" class="form-control property-form-input" 
                                       placeholder="e.g., Regional MLS" required>
                            </div>
                            <div class="col-md-6">
                                <label for="MLSNumber" class="form-label">MLS Number <span class="required">*</span></label>
                                <input type="text" id="MLSNumber" name="MLSNumber" class="form-control property-form-input" 
                                       placeholder="e.g., MLS123456" required>
                            </div>
                        </div>
                    </div>

                    <!-- Description Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-file-text"></i>
                            Property Description
                        </h3>
                        <div class="row">
                            <div class="col-12">
                                <label for="ListingDescription" class="form-label">Description <span class="required">*</span></label>
                                <textarea id="ListingDescription" name="ListingDescription" class="form-control property-form-textarea" 
                                          rows="6" placeholder="Describe the property features, location benefits, and unique selling points..." required></textarea>
                                <div class="form-text">Provide a detailed description to attract potential buyers or renters.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Amenities Section -->
                    <div class="form-section">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="section-title mb-0">
                                <i class="bi bi-star"></i>
                                Amenities & Features
                            </h3>
                            <div class="text-muted small">
                                <span id="selectedCount">0</span> selected
                            </div>
                        </div>

                        <?php if ($amenities_result && $amenities_result->num_rows > 0) : ?>
                            <!-- Search Filter -->
                            <div class="amenities-search position-relative">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" id="amenitySearch" class="form-control property-form-input" placeholder="Search amenities...">
                            </div>

                            <div class="amenities-container">
                                <div class="amenities-grid" id="amenitiesList">
                                    <?php while ($amenity = $amenities_result->fetch_assoc()) : ?>
                                        <div class="form-check amenity-item" data-name="<?php echo strtolower(htmlspecialchars($amenity['amenity_name'])); ?>">
                                            <input class="form-check-input amenity-checkbox" type="checkbox" name="amenities[]" 
                                                   value="<?php echo htmlspecialchars($amenity['amenity_id']); ?>" 
                                                   id="amenity_<?php echo htmlspecialchars($amenity['amenity_id']); ?>">
                                            <label class="form-check-label w-100" for="amenity_<?php echo htmlspecialchars($amenity['amenity_id']); ?>">
                                                <?php echo htmlspecialchars($amenity['amenity_name']); ?>
                                            </label>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                                <div id="noResults" class="text-center py-4 text-muted d-none">
                                    <i class="bi bi-emoji-frown mb-2" style="font-size: 1.5rem;"></i>
                                    <p class="mb-0">No amenities found matching your search.</p>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="text-center py-4">
                                <i class="bi bi-info-circle text-muted" style="font-size: 2rem;"></i>
                                <p class="text-muted mt-2">No amenities found. Please add some in the admin panel.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Featured Images Upload Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-images"></i>
                            Featured Property Photos
                        </h3>
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Upload general property images (exterior, interior, backyard, frontyard, etc.)
                        </p>
                        <div class="image-upload-section" id="imageUploadSection">
                            <i class="bi bi-cloud-upload upload-icon"></i>
                            <div class="upload-text">Upload Featured Images</div>
                            <div class="upload-subtext">Drag and drop images here, or click to select files</div>
                            <div class="upload-subtext">Maximum 10 images, 10MB per file • JPG, PNG, GIF supported</div>
                            
                            <div class="file-input-wrapper">
                                        <input type="file" id="property_photos" name="property_photos[]" class="file-input" 
                                               accept="image/jpeg,image/png,image/gif" multiple required>
                                        <button type="button" class="file-input-button" onclick="document.getElementById('property_photos').click()">
                                            <i class="bi bi-upload me-2"></i>Choose Images
                                        </button>
                                    </div>
                        </div>
                        
                        <!-- Image Preview Grid -->
                        <div class="image-preview-grid" id="imagePreviewGrid"></div>
                    </div>

                    <!-- Floor-by-Floor Images Upload Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-layers"></i>
                            Floor Images
                        </h3>
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Upload images for each floor of the property. The number of floor upload sections is based on the "Number of Floors" field above.
                        </p>
                        
                        <!-- Dynamic Floor Upload Containers -->
                        <div id="floorImagesContainer"></div>
                        
                        <div class="text-center mt-3" id="noFloorsMessage">
                            <i class="bi bi-building text-muted" style="font-size: 2rem;"></i>
                            <p class="text-muted mt-2">Set the number of floors above to enable floor-specific image uploads.</p>
                        </div>
                    </div>

                    <!-- Submit Section -->
                    <div class="submit-section">
                        <a href="property.php" class="btn-cancel">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="bi bi-check-circle me-2"></i>Save Property
                        </button>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Please review all information before submitting
                            </small>
                        </div>
                    </div>
                </form>
    </div>
</body>
<script src="script/add_property_script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const form = document.getElementById('propertyForm');
    const fileInput = document.getElementById('property_photos');
    const statusSelect = document.getElementById('Status');
    const rentalSection = document.getElementById('rentalDetailsSection');
    const priceLabel = document.getElementById('priceLabel');
    const priceInput = document.getElementById('ListingPrice');
    const squareInput = document.getElementById('SquareFootage');
    const lotInput = document.getElementById('LotSize');
    const squareLabel = document.getElementById('SquareFootageLabel');
    const lotLabel = document.getElementById('LotSizeLabel');
    const rentalRequiredFields = [
        document.getElementById('SecurityDeposit'),
        document.getElementById('LeaseTermMonths'),
        document.getElementById('Furnishing'),
        document.getElementById('AvailableFrom')
    ];

    // Floor Images Management
    const numberOfFloorsInput = document.getElementById('NumberOfFloors');
    const floorImagesContainer = document.getElementById('floorImagesContainer');
    const noFloorsMessage = document.getElementById('noFloorsMessage');
    let floorFileInputs = {}; // Store file inputs for each floor

    // Generate floor upload sections dynamically
    function generateFloorUploadSections(floorCount) {
        floorImagesContainer.innerHTML = '';
        floorFileInputs = {};

        if (floorCount < 1) {
            noFloorsMessage.style.display = 'block';
            return;
        }

        noFloorsMessage.style.display = 'none';

        for (let i = 1; i <= floorCount; i++) {
            const floorCard = document.createElement('div');
            floorCard.className = 'floor-upload-card';
            floorCard.innerHTML = `
                <div class="floor-header">
                    <div class="floor-title">
                        <i class="bi bi-building"></i>
                        ${getFloorLabel(i)}
                    </div>
                    <div class="floor-badge">Floor ${i}</div>
                </div>
                
                <div class="floor-upload-area" id="floorUploadArea_${i}" onclick="document.getElementById('floor_images_${i}').click()">
                    <i class="bi bi-cloud-arrow-up floor-upload-icon"></i>
                    <div class="floor-upload-text">Click to upload images for ${getFloorLabel(i)}</div>
                    <div class="floor-upload-subtext">Max 10 images per floor • JPG, PNG, GIF (10MB each)</div>
                </div>
                
                <input type="file" 
                       id="floor_images_${i}" 
                       name="floor_images_${i}[]" 
                       class="file-input" 
                       accept="image/jpeg,image/png,image/gif" 
                       multiple 
                       style="display: none;">
                
                <div class="floor-preview-grid" id="floorPreviewGrid_${i}"></div>
            `;
            
            floorImagesContainer.appendChild(floorCard);

            // Setup file input handler for this floor
            const floorInput = document.getElementById(`floor_images_${i}`);
            floorFileInputs[i] = floorInput;
            
            floorInput.addEventListener('change', function(e) {
                handleFloorImageUpload(i, e.target.files);
            });
        }
    }

    function getFloorLabel(floorNumber) {
        const labels = {
            1: 'First Floor',
            2: 'Second Floor',
            3: 'Third Floor',
            4: 'Fourth Floor',
            5: 'Fifth Floor',
            6: 'Sixth Floor',
            7: 'Seventh Floor',
            8: 'Eighth Floor',
            9: 'Ninth Floor',
            10: 'Tenth Floor'
        };
        return labels[floorNumber] || `Floor ${floorNumber}`;
    }

    function handleFloorImageUpload(floorNumber, files) {
        const uploadArea = document.getElementById(`floorUploadArea_${floorNumber}`);
        const previewGrid = document.getElementById(`floorPreviewGrid_${floorNumber}`);
        
        if (!files || files.length === 0) return;

        // Limit to 10 files
        if (files.length > 10) {
            alert(`Maximum 10 images allowed per floor. Only the first 10 will be used.`);
        }

        uploadArea.classList.add('has-files');
        previewGrid.innerHTML = '';

        const filesToProcess = Array.from(files).slice(0, 10);

        filesToProcess.forEach((file, index) => {
            // Validate file size (10MB)
            if (file.size > 10 * 1024 * 1024) {
                alert(`File ${file.name} exceeds 10MB limit.`);
                return;
            }

            // Validate file type
            if (!file.type.match('image/(jpeg|png|gif)')) {
                alert(`File ${file.name} is not a supported image format.`);
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const previewItem = document.createElement('div');
                previewItem.className = 'floor-preview-item';
                previewItem.innerHTML = `
                    <img src="${e.target.result}" alt="Floor ${floorNumber} - Image ${index + 1}" class="floor-preview-image">
                    <div class="floor-image-info">${file.name.length > 20 ? file.name.substring(0, 20) + '...' : file.name}</div>
                    <button type="button" class="remove-floor-image" onclick="removeFloorImage(${floorNumber}, ${index})" title="Remove image">
                        <i class="bi bi-x"></i>
                    </button>
                `;
                previewGrid.appendChild(previewItem);
            };
            reader.readAsDataURL(file);
        });
    }

    // Make removeFloorImage globally accessible
    window.removeFloorImage = function(floorNumber, imageIndex) {
        const floorInput = floorFileInputs[floorNumber];
        if (!floorInput) return;

        // Create a new FileList without the removed file
        const dt = new DataTransfer();
        const files = Array.from(floorInput.files);
        
        files.forEach((file, idx) => {
            if (idx !== imageIndex) {
                dt.items.add(file);
            }
        });

        floorInput.files = dt.files;

        // Re-render previews
        handleFloorImageUpload(floorNumber, floorInput.files);

        // If no files left, remove has-files class
        if (dt.files.length === 0) {
            const uploadArea = document.getElementById(`floorUploadArea_${floorNumber}`);
            uploadArea.classList.remove('has-files');
        }
    };

    // Listen to floor count changes
    if (numberOfFloorsInput) {
        numberOfFloorsInput.addEventListener('input', function() {
            const floorCount = parseInt(this.value) || 0;
            if (floorCount >= 1 && floorCount <= 10) {
                generateFloorUploadSections(floorCount);
            } else if (floorCount > 10) {
                this.value = 10;
                generateFloorUploadSections(10);
            } else {
                generateFloorUploadSections(0);
            }
        });

        // Initialize with default value (1 floor)
        const initialFloors = parseInt(numberOfFloorsInput.value) || 1;
        generateFloorUploadSections(initialFloors);
    }

    form.addEventListener('submit', function(e){
        // remove previous inline errors if present
        const prevPhoto = document.getElementById('photoError');
        if (prevPhoto) prevPhoto.remove();
        const prevRental = document.getElementById('rentalError');
        if (prevRental) prevRental.remove();

        let hasFile = false;
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            // ensure at least one file has a size > 0
            for (let i = 0; i < fileInput.files.length; i++) {
                if (fileInput.files[i].size > 0) { hasFile = true; break; }
            }
        }

        if (!hasFile) {
            e.preventDefault();
            const alertDiv = document.createElement('div');
            alertDiv.id = 'photoError';
            alertDiv.className = 'alert alert-danger';
            alertDiv.textContent = 'Please upload at least one featured property photo.';
            // insert the alert above the form
            form.parentNode.insertBefore(alertDiv, form);
            alertDiv.scrollIntoView({behavior: 'smooth', block: 'center'});
        }

        // Additional client-side validation for rentals
        const isForRent = statusSelect && statusSelect.value === 'For Rent';
        if (isForRent) {
            const errors = [];
            const dep = document.getElementById('SecurityDeposit');
            const lease = document.getElementById('LeaseTermMonths');
            const furn = document.getElementById('Furnishing');
            const avail = document.getElementById('AvailableFrom');

            const depositVal = dep && dep.value !== '' ? parseFloat(dep.value) : NaN;
            if (isNaN(depositVal) || depositVal < 0) {
                errors.push('Security Deposit must be 0 or more.');
            }

            // Validate monthly rent (ListingPrice) exists and is positive
            const listingPriceEl = document.getElementById('ListingPrice');
            const listingPriceVal = listingPriceEl && listingPriceEl.value !== '' ? parseFloat(listingPriceEl.value) : NaN;
            if (isNaN(listingPriceVal) || listingPriceVal <= 0) {
                errors.push('Monthly Rent must be a positive number.');
            }

            // Enforce deposit cap: cannot exceed 12 months of rent
            if (!isNaN(depositVal) && !isNaN(listingPriceVal) && listingPriceVal > 0) {
                const maxDeposit = listingPriceVal * 12;
                if (depositVal > maxDeposit) {
                    errors.push('Security Deposit cannot exceed 12 months of rent (₱' + maxDeposit.toFixed(2) + ').');
                }
            }

            const allowedLease = ['6','12','18','24'];
            if (!lease || !allowedLease.includes((lease.value || '').trim())) {
                errors.push('Lease Term must be one of: 6, 12, 18, or 24 months.');
            }

            const allowedFurn = ['Unfurnished','Semi-Furnished','Fully Furnished'];
            if (!furn || !allowedFurn.includes((furn.value || '').trim())) {
                errors.push('Furnishing must be selected.');
            }

            if (!avail || !avail.value) {
                errors.push('Available From date is required.');
            } else {
                const today = new Date();
                today.setHours(0,0,0,0);
                const avDate = new Date(avail.value + 'T00:00:00');
                if (isNaN(avDate.getTime())) {
                    errors.push('Available From date is invalid.');
                } else if (avDate < today) {
                    errors.push('Available From date cannot be in the past.');
                }
            }

            if (errors.length) {
                e.preventDefault();
                const alertDiv = document.createElement('div');
                alertDiv.id = 'rentalError';
                alertDiv.className = 'alert alert-danger';
                alertDiv.innerHTML = errors.map(x => `• ${x}`).join('<br>');
                form.parentNode.insertBefore(alertDiv, form);
                alertDiv.scrollIntoView({behavior: 'smooth', block: 'center'});
            }

            // Require at least one image per floor
            const floorCount = parseInt(numberOfFloorsInput && numberOfFloorsInput.value ? numberOfFloorsInput.value : '0') || 0;
            if (floorCount > 0) {
                const missingFloors = [];
                let firstErrorCard = null;
                for (let i = 1; i <= floorCount; i++) {
                    const input = document.getElementById(`floor_images_${i}`);
                    const uploadArea = document.getElementById(`floorUploadArea_${i}`);
                    const card = uploadArea ? uploadArea.closest('.floor-upload-card') : null;
                    // Clear previous error state
                    if (card) card.classList.remove('error');

                    const hasFiles = input && input.files && input.files.length > 0 && Array.from(input.files).some(f => f.size > 0);
                    if (!hasFiles) {
                        missingFloors.push(i);
                        if (card) {
                            card.classList.add('error');
                            if (!firstErrorCard) firstErrorCard = card;
                        }
                    }
                }

                if (missingFloors.length > 0) {
                    e.preventDefault();
                    // Remove existing floor error if any
                    const prevFloorErr = document.getElementById('floorImagesError');
                    if (prevFloorErr) prevFloorErr.remove();
                    const alertDiv = document.createElement('div');
                    alertDiv.id = 'floorImagesError';
                    alertDiv.className = 'alert alert-danger';
                    const missingLabel = missingFloors.map(n => `Floor ${n}`).join(', ');
                    alertDiv.innerHTML = `<strong>Floor images required:</strong> Please upload at least one image for each floor. Missing: ${missingLabel}.`;
                    // Insert error just above the submit section (after floor images section)
                    const floorSection = document.getElementById('floorImagesContainer');
                    if (floorSection && floorSection.parentNode) {
                        floorSection.parentNode.insertBefore(alertDiv, floorSection.nextSibling);
                    } else {
                        form.parentNode.insertBefore(alertDiv, form);
                    }
                    (firstErrorCard || alertDiv).scrollIntoView({behavior: 'smooth', block: 'center'});
                    return;
                }
            }
        }
    });

    function toggleRentalFields() {
        const isForRent = statusSelect && statusSelect.value === 'For Rent';
        if (isForRent) {
            rentalSection.classList.remove('d-none');
            // Switch label to Monthly Rent
            if (priceLabel) priceLabel.innerHTML = 'Monthly Rent <span class="required">*</span>';
            if (priceInput) priceInput.placeholder = 'e.g., 25000';
            // add required to rental fields
            rentalRequiredFields.forEach(el => { if (el) el.setAttribute('required', 'required'); });

            // Make Square Footage and Lot Size optional
            if (squareInput) {
                squareInput.removeAttribute('required');
                squareInput.classList.remove('is-invalid');
                squareInput.classList.add('optional-field');
            }
            if (lotInput) {
                lotInput.removeAttribute('required');
                lotInput.classList.remove('is-invalid');
                lotInput.classList.add('optional-field');
            }
            if (squareLabel) squareLabel.innerHTML = 'Square Footage <span class="optional">(Optional)</span>';
            if (lotLabel) lotLabel.innerHTML = 'Lot Size (acres) <span class="optional">(Optional)</span>';
        } else {
            rentalSection.classList.add('d-none');
            if (priceLabel) priceLabel.innerHTML = 'Listing Price <span class="required">*</span>';
            if (priceInput) priceInput.placeholder = 'e.g., 500000';
            rentalRequiredFields.forEach(el => { if (el) el.removeAttribute('required'); });

            // Require Square Footage and Lot Size for non-rent listings
            if (squareInput) {
                squareInput.setAttribute('required', 'required');
                squareInput.classList.remove('optional-field');
            }
            if (lotInput) {
                lotInput.setAttribute('required', 'required');
                lotInput.classList.remove('optional-field');
            }
            if (squareLabel) squareLabel.innerHTML = 'Square Footage <span class="required">*</span>';
            if (lotLabel) lotLabel.innerHTML = 'Lot Size (acres) <span class="required">*</span>';
        }
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', toggleRentalFields);
        // initialize on load (handles preselected value)
        toggleRentalFields();
    }

    // Amenities Search & Filter Logic
    const amenitySearch = document.getElementById('amenitySearch');
    const amenitiesList = document.getElementById('amenitiesList');
    const selectedCountSpan = document.getElementById('selectedCount');
    const noResultsMsg = document.getElementById('noResults');

    if (amenitySearch && amenitiesList) {
        const amenityItems = amenitiesList.querySelectorAll('.amenity-item');
        const checkboxes = amenitiesList.querySelectorAll('.amenity-checkbox');

        // Filter function
        amenitySearch.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase().trim();
            let hasVisibleItems = false;
            
            // Loop through all items and hide/show based on search
            const items = amenitiesList.querySelectorAll('.amenity-item');
            
            items.forEach(item => {
                const name = item.getAttribute('data-name');
                // Check if the amenity name STARTS with the search term
                if (name.startsWith(searchTerm)) {
                    item.style.display = 'flex'; // Restore flex display
                    hasVisibleItems = true;
                } else {
                    item.style.display = 'none';
                }
            });

            if (noResultsMsg) {
                if (hasVisibleItems) {
                    noResultsMsg.classList.add('d-none');
                } else {
                    noResultsMsg.classList.remove('d-none');
                }
            }
        });

        // Update count function
        function updateSelectedCount() {
            const count = Array.from(checkboxes).filter(cb => cb.checked).length;
            if (selectedCountSpan) selectedCountSpan.textContent = count;
        }

        // Add listeners to checkboxes
        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        // Make the whole item clickable
        amenityItems.forEach(item => {
            item.addEventListener('click', function(e) {
                // If the click is on the input or label, let them handle it
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
                
                const checkbox = this.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    updateSelectedCount();
                }
            });
        });
    }
});
</script>