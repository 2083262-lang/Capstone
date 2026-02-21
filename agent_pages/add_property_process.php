<?php
session_start();
include '../connection.php';

// 1. Security & Authorization
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

// 2. Server-Side Validation
$errors = [];
$required_fields = ['StreetAddress', 'City', 'County', 'State', 'ZIP', 'PropertyType', 'ListingPrice', 'Status', 'Source', 'MLSNumber'];
foreach ($required_fields as $field) {
    if (empty(trim($_POST[$field]))) {
        $errors[] = "$field is a required field.";
    }
}
if (!is_numeric($_POST['ListingPrice']) || $_POST['ListingPrice'] <= 0) {
    $errors[] = "Listing Price must be a valid, positive number.";
}
if (empty($_FILES['propertyImages']['name'][0])) {
    $errors[] = "At least one property photo is required.";
}

if (!empty($errors)) {
    $_SESSION['message'] = "Please correct the following errors: <br>" . implode("<br>", $errors);
    $_SESSION['message_type'] = "danger";
    header("Location: agent_property.php");
    exit();
}


// 3. File Upload Handling
$photo_urls = [];
$upload_dir = '../uploads/'; 
$allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
$max_size = 5 * 1024 * 1024; 

if (isset($_FILES['propertyImages'])) {
    foreach ($_FILES['propertyImages']['name'] as $key => $name) {
        if ($_FILES['propertyImages']['error'][$key] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['propertyImages']['tmp_name'][$key];
            $file_size = $_FILES['propertyImages']['size'][$key];
            $file_extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($file_extension, $allowed_types)) {
                $errors[] = "Invalid file type for '$name'.";
                continue;
            }
            if ($file_size > $max_size) {
                $errors[] = "File '$name' is too large (Max 5MB).";
                continue;
            }

            $unique_filename = uniqid('prop_', true) . '.' . $file_extension;
            $target_file = $upload_dir . $unique_filename;

            if (move_uploaded_file($tmp_name, $target_file)) {
                $photo_urls[] = 'uploads/' . $unique_filename;
            } else {
                $errors[] = "Failed to upload '$name'.";
            }
        }
    }
}

if (!empty($errors)) {
    $_SESSION['message'] = implode("<br>", $errors);
    $_SESSION['message_type'] = "danger";
    header("Location: agent_property.php");
    exit();
}

// 4. Database Transaction
$conn->begin_transaction();

try {
    $sql_property = "INSERT INTO property 
        (StreetAddress, City, County, State, ZIP, PropertyType, YearBuilt, SquareFootage, LotSize, Bedrooms, Bathrooms, ListingPrice, Status, ListingDate, Source, MLSNumber, ListingDescription, ParkingType, approval_status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
    
    $stmt_property = $conn->prepare($sql_property);
    
    // Prepare all variables
    $yearBuilt = !empty($_POST['YearBuilt']) ? $_POST['YearBuilt'] : null;
    $sqFt = !empty($_POST['SquareFootage']) ? (int)$_POST['SquareFootage'] : null;
    $lotSize = !empty($_POST['LotSize']) ? $_POST['LotSize'] : null;
    $bedrooms = !empty($_POST['Bedrooms']) ? (int)$_POST['Bedrooms'] : null;
    $bathrooms = !empty($_POST['Bathrooms']) ? (float)$_POST['Bathrooms'] : null;
    $listingDate = !empty($_POST['ListingDate']) ? $_POST['ListingDate'] : date('Y-m-d');
    $source = !empty($_POST['Source']) ? trim($_POST['Source']) : null;
    $mlsNumber = !empty($_POST['MLSNumber']) ? trim($_POST['MLSNumber']) : null;
    $county = !empty($_POST['County']) ? trim($_POST['County']) : null;
    $listingDescription = !empty($_POST['ListingDescription']) ? trim($_POST['ListingDescription']) : null;
    $parkingType = !empty($_POST['ParkingType']) ? trim($_POST['ParkingType']) : null;

    // --- THIS IS THE CORRECTED LINE ---
    // The type for ListingPrice (the 12th parameter) is now 'd' instead of 's'.
    $stmt_property->bind_param(
        "ssssssiididdssssss", 
        $_POST['StreetAddress'], $_POST['City'], $county, $_POST['State'], $_POST['ZIP'],
        $_POST['PropertyType'], $yearBuilt, $sqFt, $lotSize, $bedrooms, $bathrooms,
        $_POST['ListingPrice'], $_POST['Status'], $listingDate, $source, $mlsNumber,
        $listingDescription, $parkingType
    );
    
    $stmt_property->execute();
    $property_id = $conn->insert_id;

    // Insert Images
    if(!empty($photo_urls)) {
        $sql_image = "INSERT INTO property_images (property_ID, PhotoURL, SortOrder) VALUES (?, ?, ?)";
        $stmt_image = $conn->prepare($sql_image);
        foreach ($photo_urls as $index => $url) {
            $sort_order = $index + 1;
            $stmt_image->bind_param("isi", $property_id, $url, $sort_order);
            $stmt_image->execute();
        }
        $stmt_image->close();
    }

    // Insert Amenities
    if (!empty($_POST['amenities']) && is_array($_POST['amenities'])) {
        $sql_amenity = "INSERT INTO property_amenities (property_id, amenity_id) VALUES (?, ?)";
        $stmt_amenity = $conn->prepare($sql_amenity);
        foreach ($_POST['amenities'] as $amenity_id) {
            $stmt_amenity->bind_param("ii", $property_id, $amenity_id);
            $stmt_amenity->execute();
        }
        $stmt_amenity->close();
    }

    // Insert Logs
    // 1) Property log: creation with context (fallback if extended columns aren't present)
    $created_msg = 'Property submitted by agent and awaiting admin approval';
    $sql_log_ext = "INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message) VALUES (?, ?, 'CREATED', NOW(), ?)";
    $stmt_log = $conn->prepare($sql_log_ext);
    if ($stmt_log) {
        $stmt_log->bind_param("iis", $property_id, $agent_account_id, $created_msg);
        $stmt_log->execute();
        $stmt_log->close();
    } else {
        // Fallback minimal insert for older schemas
        $sql_log_min = "INSERT INTO property_log (property_id, account_id, action) VALUES (?, ?, 'CREATED')";
        if ($stmt_log2 = $conn->prepare($sql_log_min)) {
            $stmt_log2->bind_param("ii", $property_id, $agent_account_id);
            $stmt_log2->execute();
            $stmt_log2->close();
        }
    }

    // 2) Status log: mark as pending for lifecycle tracking
    if ($stmt_status = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'property', 'pending', ?, ?)")) {
        $stmt_status->bind_param("isi", $property_id, $created_msg, $agent_account_id);
        $stmt_status->execute();
        $stmt_status->close();
    }
    
    $conn->commit();

    $_SESSION['message'] = "Property submitted successfully! It is now pending approval.";
    $_SESSION['message_type'] = "success";

} catch (mysqli_sql_exception $exception) {
    $conn->rollback();
    $_SESSION['message'] = "A database error occurred. Please try again.";
    $_SESSION['message_type'] = "danger";
    // For debugging: error_log($exception->getMessage());
} finally {
    if (isset($stmt_property)) $stmt_property->close();
    $conn->close();
}

header("Location: agent_property.php");
exit();
?>