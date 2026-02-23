<?php
session_start();
include '../connection.php';

// 1. Security & Authorization — Agent only
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    $_SESSION['message'] = "Unauthorized access.";
    $_SESSION['message_type'] = "danger";
    header("Location: ../login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: agent_property.php");
    exit();
}

$agent_account_id = $_SESSION['account_id'];

// ========================================================================
// 2. VALIDATION (mirrors save_property.php exactly)
// ========================================================================
$errors = [];
$MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10MB per file
$ALLOWED_MIME   = ['image/jpeg', 'image/png', 'image/gif'];
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;

function getFloorLabel($floorNumber) {
    $labels = [
        1 => 'First Floor', 2 => 'Second Floor', 3 => 'Third Floor',
        4 => 'Fourth Floor', 5 => 'Fifth Floor', 6 => 'Sixth Floor',
        7 => 'Seventh Floor', 8 => 'Eighth Floor', 9 => 'Ninth Floor',
        10 => 'Tenth Floor'
    ];
    return $labels[$floorNumber] ?? ('Floor ' . (int)$floorNumber);
}

// Determine status early
$statusEarly = isset($_POST['Status']) ? trim($_POST['Status']) : '';

// Required fields (same as admin)
$required_fields = [
    'StreetAddress', 'City', 'State', 'ZIP', 'County', 'PropertyType',
    'YearBuilt', 'SquareFootage', 'LotSize', 'ParkingType', 'Bedrooms',
    'Bathrooms', 'ListingPrice', 'ListingDate', 'Source', 'MLSNumber',
    'ListingDescription', 'Status'
];

// For rentals, SquareFootage and LotSize are optional
if ($statusEarly === 'For Rent') {
    $required_fields = array_values(array_diff($required_fields, ['SquareFootage', 'LotSize']));
}

foreach ($required_fields as $field) {
    if (empty(trim($_POST[$field] ?? ''))) {
        $errors[] = "The " . htmlspecialchars($field) . " field is required.";
    }
}

// Individual field validation
$StreetAddress = trim($_POST['StreetAddress'] ?? '');
if (strlen($StreetAddress) > 255) {
    $errors[] = "Street Address cannot exceed 255 characters.";
}

$City = trim($_POST['City'] ?? '');
if (strlen($City) > 100) {
    $errors[] = "City cannot exceed 100 characters.";
}

$State = trim($_POST['State'] ?? '');
if (!preg_match('/^[A-Za-z]{2}$/', $State)) {
    $errors[] = "State must be a 2-character abbreviation.";
}

$ZIP = trim($_POST['ZIP'] ?? '');
if (!preg_match('/^\d{4}$/', $ZIP)) {
    $errors[] = "ZIP code must be exactly 4 digits (PH postal code).";
}

$PropertyType = trim($_POST['PropertyType'] ?? '');
$valid_property_types = ['Single-Family Home', 'Condominium', 'Townhouse', 'Multi-Family', 'Land', 'Commercial'];
if (!in_array($PropertyType, $valid_property_types)) {
    $errors[] = "Invalid Property Type selected.";
}

$Status = trim($_POST['Status'] ?? '');
$valid_statuses = ['For Sale', 'For Rent'];
if (!in_array($Status, $valid_statuses)) {
    $errors[] = "Invalid Status selected.";
}

$ListingPrice = filter_var($_POST['ListingPrice'] ?? '', FILTER_VALIDATE_FLOAT);
if ($ListingPrice === false || $ListingPrice <= 0) {
    $errors[] = "Listing Price must be a positive number.";
}

$YearBuilt = !empty($_POST['YearBuilt']) ? filter_var($_POST['YearBuilt'], FILTER_VALIDATE_INT) : null;
if ($YearBuilt !== null && ($YearBuilt < 1800 || $YearBuilt > date("Y") + 5)) {
    $errors[] = "Year Built is not a valid year.";
}

$SquareFootage = !empty($_POST['SquareFootage']) ? filter_var($_POST['SquareFootage'], FILTER_VALIDATE_INT) : null;
if ($SquareFootage !== null && $SquareFootage <= 0) {
    $errors[] = "Square Footage must be a positive number.";
}

$LotSize = !empty($_POST['LotSize']) ? filter_var($_POST['LotSize'], FILTER_VALIDATE_FLOAT) : null;
if ($LotSize !== null && $LotSize < 0) {
    $errors[] = "Lot Size cannot be a negative number.";
}

$Bedrooms = !empty($_POST['Bedrooms']) ? filter_var($_POST['Bedrooms'], FILTER_VALIDATE_INT) : null;
if ($Bedrooms !== null && $Bedrooms < 0) {
    $errors[] = "Bedrooms cannot be a negative number.";
}

$Bathrooms = !empty($_POST['Bathrooms']) ? filter_var($_POST['Bathrooms'], FILTER_VALIDATE_FLOAT) : null;
if ($Bathrooms !== null && $Bathrooms < 0) {
    $errors[] = "Bathrooms cannot be a negative number.";
}

$ListingDate = !empty($_POST['ListingDate']) ? $_POST['ListingDate'] : date('Y-m-d');
if (strtotime($ListingDate) > time()) {
    $errors[] = "Listing Date cannot be in the future.";
}

// ---- Rental validation (same as admin) ----
if ($statusEarly === 'For Rent') {
    $SecurityDeposit = isset($_POST['SecurityDeposit']) ? filter_var($_POST['SecurityDeposit'], FILTER_VALIDATE_FLOAT) : null;
    if ($SecurityDeposit === null || $SecurityDeposit < 0) {
        $errors[] = "Security Deposit must be a number greater than or equal to 0.";
    }

    $monthly_rent = isset($_POST['ListingPrice']) ? filter_var($_POST['ListingPrice'], FILTER_VALIDATE_FLOAT) : null;
    if ($monthly_rent === false || $monthly_rent === null || $monthly_rent <= 0) {
        $errors[] = "Monthly Rent must be a positive number.";
    }

    $LeaseTermMonths = isset($_POST['LeaseTermMonths']) ? filter_var($_POST['LeaseTermMonths'], FILTER_VALIDATE_INT) : null;
    $allowed_lease_terms = [6, 12, 18, 24];
    if ($LeaseTermMonths === null || !in_array($LeaseTermMonths, $allowed_lease_terms, true)) {
        $errors[] = "Lease Term (months) is required and must be one of: 6, 12, 18, or 24.";
    }

    $Furnishing = isset($_POST['Furnishing']) ? trim($_POST['Furnishing']) : '';
    $valid_furnishing = ['Unfurnished', 'Semi-Furnished', 'Fully Furnished'];
    if (!in_array($Furnishing, $valid_furnishing, true)) {
        $errors[] = "Furnishing must be one of: Unfurnished, Semi-Furnished, Fully Furnished.";
    }

    $AvailableFrom = isset($_POST['AvailableFrom']) ? $_POST['AvailableFrom'] : '';
    if (empty($AvailableFrom)) {
        $errors[] = "Available From date is required for rentals.";
    } else {
        $today = date('Y-m-d');
        $af_ts = strtotime($AvailableFrom);
        if ($af_ts === false) {
            $errors[] = "Available From date is invalid.";
        } elseif ($AvailableFrom < $today) {
            $errors[] = "Available From date cannot be in the past.";
        }
    }

    // Deposit cap: max 12 months rent
    if (!empty($monthly_rent) && is_numeric($monthly_rent) && is_numeric($SecurityDeposit)) {
        $max_deposit = $monthly_rent * 12;
        if ($SecurityDeposit > $max_deposit) {
            $errors[] = "Security Deposit cannot exceed 12 months of rent (₱" . number_format($max_deposit, 2) . ").";
        }
    }
}

// ---- Featured photos validation ----
$hasPhoto = false;
if (isset($_FILES['property_photos']) && is_array($_FILES['property_photos']['error'])) {
    foreach ($_FILES['property_photos']['error'] as $fileErr) {
        if ($fileErr === UPLOAD_ERR_OK) { $hasPhoto = true; break; }
    }
}
if (!$hasPhoto) {
    $errors[] = "Please upload at least one featured property photo.";
}

// Featured image size & MIME check
if (isset($_FILES['property_photos']) && is_array($_FILES['property_photos']['name'])) {
    $files = $_FILES['property_photos'];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            if ($files['size'][$i] > $MAX_IMAGE_SIZE) {
                $errors[] = "Featured image '" . htmlspecialchars($files['name'][$i]) . "' exceeds 10MB limit.";
            }
            if ($finfo) {
                $mime = finfo_file($finfo, $files['tmp_name'][$i]);
                if (!in_array($mime, $ALLOWED_MIME, true)) {
                    $errors[] = "Featured image '" . htmlspecialchars($files['name'][$i]) . "' is not a supported type (JPEG/PNG/GIF).";
                }
            }
        }
    }
}

// ---- Floor validation ----
$NumberOfFloors = isset($_POST['NumberOfFloors']) ? (int)$_POST['NumberOfFloors'] : 1;
if ($NumberOfFloors < 1 || $NumberOfFloors > 10) {
    $errors[] = "Number of Floors must be between 1 and 10.";
}

// Require at least one image per floor
for ($floor = 1; $floor <= max(1, $NumberOfFloors); $floor++) {
    $key = 'floor_images_' . $floor;
    $hasAtLeastOne = false;
    if (isset($_FILES[$key]) && is_array($_FILES[$key]['error'])) {
        foreach ($_FILES[$key]['error'] as $idx => $err) {
            if ($err === UPLOAD_ERR_OK && isset($_FILES[$key]['size'][$idx]) && $_FILES[$key]['size'][$idx] > 0) {
                $hasAtLeastOne = true;
                break;
            }
        }
    }
    if (!$hasAtLeastOne) {
        $errors[] = 'Please upload at least one image for ' . getFloorLabel($floor) . '.';
    }
}

// Floor images size & MIME check per floor
for ($floor = 1; $floor <= max(1, $NumberOfFloors); $floor++) {
    $key = 'floor_images_' . $floor;
    if (isset($_FILES[$key]) && is_array($_FILES[$key]['name'])) {
        $ff = $_FILES[$key];
        $cnt = count($ff['name']);
        for ($j = 0; $j < $cnt; $j++) {
            if ($ff['error'][$j] === UPLOAD_ERR_OK) {
                if ($ff['size'][$j] > $MAX_IMAGE_SIZE) {
                    $errors[] = getFloorLabel($floor) . ": '" . htmlspecialchars($ff['name'][$j]) . "' exceeds 10MB limit.";
                }
                if ($finfo) {
                    $mime = finfo_file($finfo, $ff['tmp_name'][$j]);
                    if (!in_array($mime, $ALLOWED_MIME, true)) {
                        $errors[] = getFloorLabel($floor) . ": '" . htmlspecialchars($ff['name'][$j]) . "' is not a supported type (JPEG/PNG/GIF).";
                    }
                }
            }
        }
    }
}

if ($finfo) { finfo_close($finfo); }

// ========================================================================
// 3. PROCESS IF NO ERRORS
// ========================================================================
if (empty($errors)) {
    // Sanitized variables
    $StreetAddress     = trim($_POST['StreetAddress']);
    $City              = trim($_POST['City']);
    $State             = trim($_POST['State']);
    $ZIP               = trim($_POST['ZIP']);
    $County            = !empty($_POST['County']) ? trim($_POST['County']) : null;
    $PropertyType      = trim($_POST['PropertyType']);
    $ListingPrice      = (float)$_POST['ListingPrice'];
    $Status            = trim($_POST['Status']);
    $YearBuilt         = !empty($_POST['YearBuilt']) ? (int)$_POST['YearBuilt'] : null;
    $SquareFootage     = !empty($_POST['SquareFootage']) ? (int)$_POST['SquareFootage'] : null;
    $LotSize           = !empty($_POST['LotSize']) ? (float)$_POST['LotSize'] : null;
    $Bedrooms          = !empty($_POST['Bedrooms']) ? (int)$_POST['Bedrooms'] : null;
    $Bathrooms         = !empty($_POST['Bathrooms']) ? (float)$_POST['Bathrooms'] : null;
    $ListingDate       = !empty($_POST['ListingDate']) ? $_POST['ListingDate'] : date('Y-m-d');
    $Source            = !empty($_POST['Source']) ? trim($_POST['Source']) : null;
    $MLSNumber         = !empty($_POST['MLSNumber']) ? trim($_POST['MLSNumber']) : null;
    $ListingDescription = !empty($_POST['ListingDescription']) ? trim($_POST['ListingDescription']) : null;
    $ParkingType       = !empty($_POST['ParkingType']) ? trim($_POST['ParkingType']) : null;

    // Rental fields
    $SecurityDeposit  = isset($_POST['SecurityDeposit']) && $_POST['SecurityDeposit'] !== '' ? (float)$_POST['SecurityDeposit'] : null;
    $LeaseTermMonths  = isset($_POST['LeaseTermMonths']) && $_POST['LeaseTermMonths'] !== '' ? (int)$_POST['LeaseTermMonths'] : null;
    $Furnishing       = isset($_POST['Furnishing']) && $_POST['Furnishing'] !== '' ? trim($_POST['Furnishing']) : null;
    $AvailableFrom    = isset($_POST['AvailableFrom']) && $_POST['AvailableFrom'] !== '' ? $_POST['AvailableFrom'] : null;

    // Agent properties always start as 'pending' for review
    $approval_status = 'pending';

    $conn->begin_transaction();

    try {
        // ---- Insert Property ----
        $sql = "INSERT INTO property (
            StreetAddress, City, County, State, ZIP, PropertyType, YearBuilt, SquareFootage, LotSize,
            Bedrooms, Bathrooms, ListingPrice, Status, ListingDate,
            Source, MLSNumber, ListingDescription, ParkingType, approval_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssssssiididssssssss",
            $StreetAddress, $City, $County, $State, $ZIP, $PropertyType,
            $YearBuilt, $SquareFootage, $LotSize, $Bedrooms, $Bathrooms,
            $ListingPrice, $Status, $ListingDate, $Source, $MLSNumber,
            $ListingDescription, $ParkingType, $approval_status
        );
        $stmt->execute();
        $property_id = $conn->insert_id;
        $stmt->close();

        // ---- Property Log ----
        $created_msg = 'Property submitted by agent and awaiting admin approval';
        $sql_log = "INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message) VALUES (?, ?, 'CREATED', NOW(), ?)";
        $stmt_log = $conn->prepare($sql_log);
        if ($stmt_log) {
            $stmt_log->bind_param("iis", $property_id, $agent_account_id, $created_msg);
            $stmt_log->execute();
            $stmt_log->close();
        } else {
            // Fallback for older schema
            $sql_log2 = "INSERT INTO property_log (property_id, account_id, action) VALUES (?, ?, 'CREATED')";
            if ($stmt_log2 = $conn->prepare($sql_log2)) {
                $stmt_log2->bind_param("ii", $property_id, $agent_account_id);
                $stmt_log2->execute();
                $stmt_log2->close();
            }
        }

        // ---- Status Log ----
        if ($stmt_status = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'property', 'pending', ?, ?)")) {
            $stmt_status->bind_param("isi", $property_id, $created_msg, $agent_account_id);
            $stmt_status->execute();
            $stmt_status->close();
        }

        // ---- Rental Details ----
        if ($Status === 'For Rent') {
            $rental_sql = "INSERT INTO rental_details (property_id, monthly_rent, security_deposit, lease_term_months, furnishing, available_from) VALUES (?, ?, ?, ?, ?, ?)";
            if ($rental_stmt = $conn->prepare($rental_sql)) {
                $monthly_rent_val = $ListingPrice;
                $rental_stmt->bind_param("iddiss", $property_id, $monthly_rent_val, $SecurityDeposit, $LeaseTermMonths, $Furnishing, $AvailableFrom);
                $rental_stmt->execute();
                $rental_stmt->close();
            }
        }

        // ---- Amenities ----
        if (!empty($_POST['amenities']) && is_array($_POST['amenities'])) {
            $sql_amenity = "INSERT INTO property_amenities (property_id, amenity_id) VALUES (?, ?)";
            $stmt_amenity = $conn->prepare($sql_amenity);
            foreach ($_POST['amenities'] as $amenity_id) {
                $sanitized = (int)$amenity_id;
                $stmt_amenity->bind_param("ii", $property_id, $sanitized);
                $stmt_amenity->execute();
            }
            $stmt_amenity->close();
        }

        // ---- Featured Images Upload ----
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

        if (isset($_FILES['property_photos']) && is_array($_FILES['property_photos']['name'])) {
            $files = $_FILES['property_photos'];
            $file_count = count($files['name']);
            $sql_image = "INSERT INTO property_images (property_ID, PhotoURL, SortOrder) VALUES (?, ?, ?)";
            $stmt_image = $conn->prepare($sql_image);

            for ($i = 0; $i < $file_count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    if ($files['size'][$i] > $MAX_IMAGE_SIZE) continue;
                    $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) continue;

                    $new_name = uniqid('prop_', true) . '.' . $ext;
                    $destination = $upload_dir . $new_name;

                    if (move_uploaded_file($files['tmp_name'][$i], $destination)) {
                        $db_path = 'uploads/' . $new_name;
                        $sort = $i + 1;
                        $stmt_image->bind_param("isi", $property_id, $db_path, $sort);
                        $stmt_image->execute();
                    }
                }
            }
            $stmt_image->close();
        }

        // ---- Floor Images Upload ----
        $floor_sql = "INSERT INTO property_floor_images (property_id, floor_number, photo_url, sort_order) VALUES (?, ?, ?, ?)";
        for ($floor = 1; $floor <= max(1, $NumberOfFloors); $floor++) {
            $key = 'floor_images_' . $floor;
            if (!empty($_FILES[$key]['name'][0])) {
                $floor_dir = '../uploads/floors/' . $property_id . '/floor_' . $floor . '/';
                if (!is_dir($floor_dir)) { mkdir($floor_dir, 0755, true); }

                $ff = $_FILES[$key];
                $cnt = count($ff['name']);
                for ($j = 0; $j < $cnt; $j++) {
                    if ($ff['error'][$j] === UPLOAD_ERR_OK) {
                        if ($ff['size'][$j] > $MAX_IMAGE_SIZE) continue;
                        $ext = strtolower(pathinfo($ff['name'][$j], PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) continue;

                        $new_name = uniqid('floor_' . $floor . '_', true) . '.' . $ext;
                        $dest = $floor_dir . $new_name;
                        if (move_uploaded_file($ff['tmp_name'][$j], $dest)) {
                            if ($pis = $conn->prepare($floor_sql)) {
                                $sort = $j + 1;
                                $pis->bind_param('iisi', $property_id, $floor, $dest, $sort);
                                $pis->execute();
                                $pis->close();
                            }
                        }
                    }
                }
            }
        }

        $conn->commit();

        $_SESSION['message'] = "Property submitted successfully! It is now pending admin approval.";
        $_SESSION['message_type'] = "success";

    } catch (Exception $exception) {
        $conn->rollback();
        $errors[] = "A database error occurred. Please try again.";
        error_log("Agent add_property_process error: " . $exception->getMessage());
    }
}

// If errors, store in session
if (!empty($errors)) {
    $_SESSION['message'] = "Please correct the following errors:<br>" . implode("<br>", $errors);
    $_SESSION['message_type'] = "danger";
}

if (isset($conn) && $conn) { $conn->close(); }

header("Location: agent_property.php");
exit();
?>
