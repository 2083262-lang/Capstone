<?php
session_start();
include '../connection.php';

// Check if the user is logged in AND their role is 'agent'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: login.php");
    exit();
}
$agent_account_id = $_SESSION['account_id'];

// --- PHP code to fetch agent info ---
$agent_info_sql = "
    SELECT a.first_name, a.username, ai.profile_picture_url
    FROM accounts a 
    JOIN agent_information ai ON a.account_id = ai.account_id
    WHERE a.account_id = ?";
$stmt_agent_info = $conn->prepare($agent_info_sql);
$stmt_agent_info->bind_param("i", $agent_account_id);
$stmt_agent_info->execute();
$agent = $stmt_agent_info->get_result()->fetch_assoc();
$stmt_agent_info->close();

// --- SQL QUERY: Using property_log to fetch agent's properties ---
$properties_sql = "
    SELECT p.*, pi.PhotoURL,
           rd.monthly_rent AS rd_monthly_rent,
           rd.security_deposit AS rd_security_deposit,
           rd.lease_term_months AS rd_lease_term_months,
           rd.furnishing AS rd_furnishing,
           rd.available_from AS rd_available_from
    FROM property p
    JOIN property_log pl ON p.property_ID = pl.property_id
    LEFT JOIN property_images pi ON p.property_ID = pi.property_ID AND pi.SortOrder = 1
    LEFT JOIN rental_details rd ON rd.property_id = p.property_ID
    WHERE pl.account_id = ? AND pl.action = 'CREATED'
    GROUP BY p.property_ID 
    ORDER BY p.ListingDate DESC";

$stmt_properties = $conn->prepare($properties_sql);
$stmt_properties->bind_param("i", $agent_account_id);
$stmt_properties->execute();
$result = $stmt_properties->get_result();
$all_properties = $result->fetch_all(MYSQLI_ASSOC);
$stmt_properties->close();

// Separate properties into categories
$approved_properties = array_filter($all_properties, fn($p) => $p['approval_status'] == 'approved' && $p['Status'] != 'Pending Sold' && $p['Status'] != 'Sold');
$pending_properties = array_filter($all_properties, fn($p) => $p['approval_status'] == 'pending');
$rejected_properties = array_filter($all_properties, fn($p) => $p['approval_status'] == 'rejected');
$pending_sold_properties = array_filter($all_properties, fn($p) => $p['Status'] == 'Pending Sold');
$sold_properties = array_filter($all_properties, fn($p) => $p['Status'] == 'Sold');

// Fetch amenities for the modal form
$amenities_result = $conn->query("SELECT * FROM amenities ORDER BY amenity_name");
$amenities = $amenities_result->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Dashboard - Your Real Estate</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --background-color: #f8f4f4;
            --card-bg-color: #ffffff;
            --border-color: #e6e6e6;
            --text-muted: #6c757d;
            --shadow-light: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-medium: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-heavy: 0 8px 32px rgba(0,0,0,0.15);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background-color);
            margin: 0;
        }

        /* Main Content */
        .container {
            max-width: 1600px;
        }
        
        .btn-brand {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            color: var(--primary-color);
            font-weight: 600;
        }

        .btn-brand:hover {
            background-color: #a98f3a;
            border-color: #a98f3a;
            color: var(--primary-color);
        }

        .btn-primary {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            color: var(--primary-color);
            font-weight: 600;
        }

        .btn-primary:hover {
            background-color: #a98f3a;
            border-color: #a98f3a;
            color: var(--primary-color);
        }

        .modal-header {
            background-color: var(--primary-color);
            color: #fff;
        }

        .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        .form-label {
            font-weight: 500;
        }
        
        /* Box Layout Styles */
        .stat-card {
            background-color: var(--card-bg-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            align-items: center;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.07);
        }
        .stat-card .icon {
            font-size: 2.2rem;
            padding: 1rem;
            border-radius: 50%;
            margin-right: 1.5rem;
            color: var(--secondary-color);
            background-color: rgba(188, 158, 66, 0.1);
        }
        .stat-card .stat-title {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }
        .stat-card .stat-number {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .listings-tabs .nav-link { color: var(--text-color); font-weight: 600; border-bottom: 3px solid transparent; }
        .listings-tabs .nav-link.active { color: var(--secondary-color); border-bottom-color: var(--secondary-color); background-color: transparent; }
        
        /* Property Card styles remain the same */
        .property-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
            background-color: var(--card-bg-color);
        }
        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        }
        .property-card-img-container {
            position: relative;
            height: 200px;
        }
        .property-card-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .approval-status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.4em 0.8em;
            border-radius: 50px;
            border: 1px solid rgba(0,0,0,0.1);
        }
        .property-card-body {
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
        }
        .property-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }
        .property-location {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        .property-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }
        .property-footer {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.85rem;
            color: #6c757d;
        }
        .empty-tab-message {
            background-color: var(--card-bg-color);
            padding: 3rem;
            border-radius: 8px;
            text-align: center;
        }
        .add-property-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            background: linear-gradient(45deg, var(--secondary-color-light), var(--secondary-color));
            color: #161209;
            font-size: 1.75rem;
            border-radius: 50%;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }
        .add-property-icon:hover {
            transform: translateY(-4px) scale(1.05);
            box-shadow: 0 8px 25px rgba(188, 158, 66, 0.4);
            color: #a98f3a;
        }
        
        /* Redesigned Modal Styles */
        .modal-header.modal-header-custom { background-color: var(--primary-color); color: #fff; border-bottom: 3px solid var(--secondary-color); }
        .modal-header-custom .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .nav-tabs .nav-link { color: #6c757d; border: none; border-bottom: 2px solid transparent; }
        .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom-color: var(--secondary-color); background-color: transparent; font-weight: 600; }
        .form-section-title { font-weight: 600; color: var(--primary-color); margin-bottom: 1.5rem; }
        .amenity-grid { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .amenity-item .form-check-input { display: none; }
        .amenity-item .form-check-label { background-color: #f8f9fa; border: 1px solid #dee2e6; padding: 0.375rem 1rem; border-radius: 50px; cursor: pointer; transition: all 0.2s ease-in-out; font-weight: 500; }
        .amenity-item .form-check-input:checked + .form-check-label { background-color: var(--secondary-color); color: var(--primary-color); border-color: var(--secondary-color); }
        .image-preview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 1rem; }
        .preview-image { width: 100%; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color); }
        .modal-footer { border-top: 1px solid var(--border-color); }
    </style>
</head>
<body>

<?php
// Prepare variables for navbar
$agent_username = $agent['username'] ?? 'Agent';
$agent_info = [
    'profile_picture_url' => $agent['profile_picture_url'] ?? 'https://via.placeholder.com/40'
];

// Set this file as active in navbar
$active_page = 'agent_property.php';
include 'agent_navbar.php';
?>

<main class="container py-4">
        <div class="d-flex justify-content-end align-items-center mb-4">
            <a href="#" class="add-property-icon" data-bs-toggle="modal" data-bs-target="#addPropertyModal" title="Add New Property">
                <i class="bi bi-house-add-fill"></i>
            </a>
        </div>
    
    <!-- Stat Box Layout -->
    <div class="row">
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="icon"><i class="bi bi-patch-check-fill"></i></div>
                <div>
                    <div class="stat-title">Active Listings</div>
                    <div class="stat-number text-success"><?php echo count($approved_properties); ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-title">Pending Review</div>
                    <div class="stat-number text-warning"><?php echo count($pending_properties); ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="icon"><i class="bi bi-currency-exchange"></i></div>
                <div>
                    <div class="stat-title">Pending Sold</div>
                    <div class="stat-number text-info"><?php echo count($pending_sold_properties); ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="icon"><i class="bi bi-trophy-fill"></i></div>
                <div>
                    <div class="stat-title">Sold Properties</div>
                    <div class="stat-number text-dark"><?php echo count($sold_properties); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-lg-6 col-md-12">
            <div class="stat-card">
                <div class="icon"><i class="bi bi-x-circle-fill"></i></div>
                <div>
                    <div class="stat-title">Rejected Listings</div>
                    <div class="stat-number text-danger"><?php echo count($rejected_properties); ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-md-12">
            <div class="stat-card">
                <div class="icon"><i class="bi bi-collection-fill"></i></div>
                <div>
                    <div class="stat-title">Total Properties</div>
                    <div class="stat-number"><?php echo count($all_properties); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <div class="listings-tabs">
        <ul class="nav nav-tabs mb-4" id="propertyStatusTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved-content" type="button" role="tab">
                    Active <span class="badge bg-success-subtle text-success-emphasis rounded-pill"><?php echo count($approved_properties); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-content" type="button" role="tab">
                    Pending Review <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill"><?php echo count($pending_properties); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected-content" type="button" role="tab">
                    Rejected <span class="badge bg-danger-subtle text-danger-emphasis rounded-pill"><?php echo count($rejected_properties); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pending-sold-tab" data-bs-toggle="tab" data-bs-target="#pending-sold-content" type="button" role="tab">
                    Pending Sold <span class="badge bg-info-subtle text-info-emphasis rounded-pill"><?php echo count($pending_sold_properties); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="sold-tab" data-bs-toggle="tab" data-bs-target="#sold-content" type="button" role="tab">
                    Sold <span class="badge bg-dark-subtle text-dark-emphasis rounded-pill"><?php echo count($sold_properties); ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="propertyStatusTabsContent">
            <div class="tab-pane fade show active" id="approved-content" role="tabpanel">
                <?php if (empty($approved_properties)): ?>
                    <div class="empty-tab-message"><p class="mb-0 text-muted">No approved properties found.</p></div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($approved_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="pending-content" role="tabpanel">
                 <?php if (empty($pending_properties)): ?>
                    <div class="empty-tab-message"><p class="mb-0 text-muted">You have no properties pending review.</p></div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($pending_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="rejected-content" role="tabpanel">
                 <?php if (empty($rejected_properties)): ?>
                    <div class="empty-tab-message"><p class="mb-0 text-muted">No rejected properties found.</p></div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($rejected_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="tab-pane fade" id="pending-sold-content" role="tabpanel">
                 <?php if (empty($pending_sold_properties)): ?>
                    <div class="empty-tab-message"><p class="mb-0 text-muted">No properties with pending sale.</p></div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($pending_sold_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="tab-pane fade" id="sold-content" role="tabpanel">
                 <?php if (empty($sold_properties)): ?>
                    <div class="empty-tab-message"><p class="mb-0 text-muted">No sold properties found.</p></div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($sold_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="addPropertyModal" tabindex="-1" aria-labelledby="addPropertyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title" id="addPropertyModalLabel"><i class="bi bi-house-add-fill me-2"></i>Create New Property Listing</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="add_property_process.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="addPropertyTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="step1-tab" data-bs-toggle="tab" data-bs-target="#step1-content" type="button" role="tab"><b>Step 1:</b> Details</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="step2-tab" data-bs-toggle="tab" data-bs-target="#step2-content" type="button" role="tab"><b>Step 2:</b> Features</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="step3-tab" data-bs-toggle="tab" data-bs-target="#step3-content" type="button" role="tab"><b>Step 3:</b> Media</button>
                        </li>
                    </ul>

                    <div class="tab-content py-4" id="addPropertyTabsContent">
                        <div class="tab-pane fade show active" id="step1-content" role="tabpanel">
                            <h5 class="form-section-title">Property Location</h5>
                            <div class="row g-3">
                                <div class="col-12"><label class="form-label">Street Address *</label><input type="text" class="form-control" name="StreetAddress" required></div>
                                <div class="col-md-4"><label class="form-label">City *</label><input type="text" class="form-control" name="City" required></div>
                                <div class="col-md-4"><label class="form-label">County</label><input type="text" class="form-control" name="County"></div>
                                <div class="col-md-2"><label class="form-label">State (2-char) *</label><input type="text" class="form-control" name="State" required maxlength="2"></div>
                                <div class="col-md-2"><label class="form-label">ZIP *</label><input type="text" class="form-control" name="ZIP" required></div>
                            </div>
                            <hr class="my-4">
                            <h5 class="form-section-title">Listing Information</h5>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Property Type *</label><select class="form-select" name="PropertyType" required><option selected disabled value="">Choose...</option><option>Single-Family Home</option><option>Condominium</option><option>Townhouse</option><option>Multi-Family</option><option>Commercial</option><option>Land</option></select></div>
                                <div class="col-md-4"><label class="form-label">Listing Price (PHP) *</label><input type="number" class="form-control" name="ListingPrice" step="0.01" min="0" required></div>
                                <div class="col-md-4"><label class="form-label">Listing Status *</label><select class="form-select" name="Status" required><option selected disabled value="">Choose...</option><option>For Sale</option><option>For Rent</option></select></div>
                                
                                <!-- ADDED MISSING INPUTS HERE -->
                                <div class="col-md-6"><label class="form-label">Source (e.g., Local MLS)*</label><input type="text" class="form-control" name="Source" required></div>
                                <div class="col-md-6"><label class="form-label">MLS Number*</label><input type="text" class="form-control" name="MLSNumber" required></div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="step2-content" role="tabpanel">
                            <h5 class="form-section-title">Property Specifications</h5>
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label">Bedrooms</label><input type="number" class="form-control" name="Bedrooms" min="0" value="0"></div>
                                <div class="col-md-3"><label class="form-label">Bathrooms</label><input type="number" class="form-control" name="Bathrooms" step="0.5" min="0" value="0"></div>
                                <div class="col-md-3"><label class="form-label">Square Footage</label><input type="number" class="form-control" name="SquareFootage" min="0"></div>
                                <div class="col-md-3"><label class="form-label">Lot Size (acres)</label><input type="number" class="form-control" name="LotSize" step="0.01" min="0"></div>
                                <div class="col-md-6"><label class="form-label">Year Built</label><input type="number" class="form-control" name="YearBuilt" min="1800" max="<?php echo date('Y'); ?>"></div>
                                <div class="col-md-6"><label class="form-label">Parking Type</label><input type="text" class="form-control" name="ParkingType"></div>
                            </div>
                            <hr class="my-4">
                            <h5 class="form-section-title">Amenities</h5>
                            <div class="amenity-grid">
                                <?php if (!empty($amenities)): ?>
                                    <?php foreach ($amenities as $amenity): ?>
                                        <div class="form-check form-check-inline amenity-item">
                                            <input class="form-check-input" type="checkbox" name="amenities[]" value="<?php echo htmlspecialchars($amenity['amenity_id']); ?>" id="modal_amenity_<?php echo htmlspecialchars($amenity['amenity_id']); ?>">
                                            <label class="form-check-label" for="modal_amenity_<?php echo htmlspecialchars($amenity['amenity_id']); ?>"><?php echo htmlspecialchars($amenity['amenity_name']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">No amenities available to select.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="step3-content" role="tabpanel">
                            <h5 class="form-section-title">Property Description</h5>
                            <div class="mb-4">
                                <label class="form-label">Listing Description *</label>
                                <textarea class="form-control" name="ListingDescription" rows="5" placeholder="Describe the key features and highlights of the property..." required></textarea>
                            </div>
                            <hr class="my-4">
                            <h5 class="form-section-title">Property Photos</h5>
                            <div>
                                <label for="propertyImages" class="form-label">Upload Images *</label>
                                <input class="form-control" type="file" id="propertyImages" name="propertyImages[]" multiple required accept="image/*">
                                <div class="form-text">You can select multiple images. The first image selected will be the main photo.</div>
                                <div class="image-preview-grid mt-3" id="imagePreviewContainer"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-outline-secondary btn-prev d-none">Previous</button>
                    <button type="button" class="btn btn-brand btn-next">Next</button>
                    <button type="submit" class="btn btn-success btn-submit d-none">Submit for Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark as Sold Modal -->
<div class="modal fade" id="markSoldModal" tabindex="-1" aria-labelledby="markSoldModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title" id="markSoldModalLabel">
                    <i class="fas fa-check-circle me-2"></i>Mark Property as Sold
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="markSoldForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info mb-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Sale Verification Process:</strong> Submit documents for admin review. Your property will be marked as sold once verified.
                    </div>
                    
                    <input type="hidden" id="propertyId" name="property_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Property:</label>
                        <p id="propertyTitle" class="mb-0 text-muted"></p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="salePrice" class="form-label">Final Sale Price <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" id="salePrice" name="sale_price" 
                                       step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="saleDate" class="form-label">Sale Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="saleDate" name="sale_date" 
                                   max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="buyerName" class="form-label">Buyer Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="buyerName" name="buyer_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="buyerContact" class="form-label">Buyer Contact</label>
                        <input type="text" class="form-control" id="buyerContact" name="buyer_contact" 
                               placeholder="Phone number or email">
                    </div>
                    
                    <div class="mb-3">
                        <label for="saleDocuments" class="form-label">
                            Sale Documents <span class="text-danger">*</span>
                            <small class="text-muted d-block">Upload deed of sale, contracts, or other proof documents</small>
                        </label>
                        <input type="file" class="form-control" id="saleDocuments" name="sale_documents[]" 
                               multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                        <div class="form-text">
                            Allowed formats: PDF, Images (JPG, PNG), Word documents. Max 10MB per file.
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="additionalNotes" class="form-label">Additional Notes</label>
                        <textarea class="form-control" id="additionalNotes" name="additional_notes" 
                                  rows="3" placeholder="Any additional information about the sale..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-upload me-1"></i>Submit for Verification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'logout_agent_modal.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const addPropertyModal = document.getElementById('addPropertyModal');
    if (addPropertyModal) {
        const tabTriggers = addPropertyModal.querySelectorAll('#addPropertyTabs button');
        const tabPanes = addPropertyModal.querySelectorAll('.tab-pane');
        const btnPrev = addPropertyModal.querySelector('.btn-prev');
        const btnNext = addPropertyModal.querySelector('.btn-next');
        const btnSubmit = addPropertyModal.querySelector('.btn-submit');
        let currentTab = 0;

        function validateStep(stepIndex) {
            let isValid = true;
            const currentPane = tabPanes[stepIndex];
            const requiredInputs = currentPane.querySelectorAll('[required]');
            
            requiredInputs.forEach(input => {
                input.classList.remove('is-invalid');
                if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('is-invalid');
                }
            });
            return isValid;
        }

        function updateButtons() {
            btnPrev.classList.toggle('d-none', currentTab === 0);
            btnNext.classList.toggle('d-none', currentTab === tabTriggers.length - 1);
            btnSubmit.classList.toggle('d-none', currentTab !== tabTriggers.length - 1);
        }

        btnNext.addEventListener('click', function () {
            if (!validateStep(currentTab)) { return; }
            if (currentTab < tabTriggers.length - 1) {
                currentTab++;
                new bootstrap.Tab(tabTriggers[currentTab]).show();
            }
        });

        btnPrev.addEventListener('click', function () {
            if (currentTab > 0) {
                currentTab--;
                new bootstrap.Tab(tabTriggers[currentTab]).show();
            }
        });

        tabTriggers.forEach((tab, index) => {
            tab.addEventListener('shown.bs.tab', function () {
                currentTab = index;
                updateButtons();
            });
        });

        const imageInput = document.getElementById('propertyImages');
        const previewContainer = document.getElementById('imagePreviewContainer');
        
        imageInput.addEventListener('change', function(event) {
            previewContainer.innerHTML = '';
            if (event.target.files) {
                Array.from(event.target.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.classList.add('preview-image');
                        previewContainer.appendChild(img);
                    }
                    reader.readAsDataURL(file);
                });
            }
        });

        addPropertyModal.addEventListener('hidden.bs.modal', function () {
            // Reset form and tabs when modal is closed
            addPropertyModal.querySelector('form').reset();
            previewContainer.innerHTML = '';
            new bootstrap.Tab(tabTriggers[0]).show();
        });

        updateButtons();
    }

    // Mark as Sold Modal Functionality
    const markSoldModal = document.getElementById('markSoldModal');
    if (markSoldModal) {
        markSoldModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const propertyId = button.getAttribute('data-property-id');
            const propertyTitle = button.getAttribute('data-property-title');
            
            document.getElementById('propertyId').value = propertyId;
            document.getElementById('propertyTitle').textContent = propertyTitle;
        });

        const markSoldForm = document.getElementById('markSoldForm');
        markSoldForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Submitting...';
            
            const formData = new FormData(this);
            
            fetch('mark_as_sold_process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="fas fa-check-circle me-2"></i>
                        ${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    
                    // Insert alert at top of page (robust container selection)
                    const container = document.querySelector('main.container') || document.querySelector('.container') || document.body;
                    container.insertBefore(alertDiv, container.firstChild);
                    
                    // Close modal and reset form
                    bootstrap.Modal.getInstance(markSoldModal).hide();
                    markSoldForm.reset();
                    
                    // Reload page after short delay to show updated status
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    // Show error message
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    
                    // Insert alert in modal body
                    const modalBody = markSoldModal.querySelector('.modal-body');
                    modalBody.insertBefore(alertDiv, modalBody.firstChild);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                alertDiv.innerHTML = `
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    An error occurred while submitting. Please try again.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                
                const modalBody = markSoldModal.querySelector('.modal-body');
                modalBody.insertBefore(alertDiv, modalBody.firstChild);
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        markSoldModal.addEventListener('hidden.bs.modal', function () {
            // Clear any alerts and reset form
            const alerts = this.querySelectorAll('.alert');
            alerts.forEach(alert => alert.remove());
            markSoldForm.reset();
        });
    }
});
</script>
</body>
</html>