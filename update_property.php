<?php
session_start();
require_once 'connection.php';
require_once __DIR__ . '/config/session_timeout.php';

header('Content-Type: application/json');

// Check admin access
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$property_id = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;

if ($property_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid property ID']);
    exit;
}

try {
    $conn->begin_transaction();
    
    // Verify property exists
    $check = $conn->prepare("SELECT property_ID FROM property WHERE property_ID = ? LIMIT 1");
    $check->bind_param('i', $property_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        throw new Exception('Property not found');
    }
    $check->close();
    
    // Extract and validate input fields
    $fields = [
        'StreetAddress' => trim($_POST['StreetAddress'] ?? ''),
        'City' => trim($_POST['City'] ?? ''),
        'Province' => trim($_POST['Province'] ?? ''),
        'ZIP' => trim($_POST['ZIP'] ?? ''),
        'Barangay' => trim($_POST['Barangay'] ?? ''),
        'PropertyType' => trim($_POST['PropertyType'] ?? ''),
        'Status' => trim($_POST['Status'] ?? ''),
        'YearBuilt' => (isset($_POST['YearBuilt']) && $_POST['YearBuilt'] !== '') ? (int)$_POST['YearBuilt'] : null,
        'Bedrooms' => (isset($_POST['Bedrooms']) && $_POST['Bedrooms'] !== '') ? (int)$_POST['Bedrooms'] : null,
        'Bathrooms' => (isset($_POST['Bathrooms']) && $_POST['Bathrooms'] !== '') ? (float)$_POST['Bathrooms'] : null,
        'ListingDate' => trim($_POST['ListingDate'] ?? ''),
        'ListingPrice' => isset($_POST['ListingPrice']) ? (float)$_POST['ListingPrice'] : 0,
        'SquareFootage' => isset($_POST['SquareFootage']) ? (int)$_POST['SquareFootage'] : 0,
        'LotSize' => (isset($_POST['LotSize']) && $_POST['LotSize'] !== '') ? (float)$_POST['LotSize'] : null,
        'ParkingType' => trim($_POST['ParkingType'] ?? ''),
        'Source' => trim($_POST['Source'] ?? ''),
        'MLSNumber' => trim($_POST['MLSNumber'] ?? ''),
        'ListingDescription' => trim($_POST['ListingDescription'] ?? '')
    ];
    
    // Rental fields (required when Status is 'For Rent')
    $rentalFields = [
        'SecurityDeposit' => isset($_POST['SecurityDeposit']) && $_POST['SecurityDeposit'] !== '' ? (float)$_POST['SecurityDeposit'] : 0.00,
        'LeaseTermMonths' => isset($_POST['LeaseTermMonths']) && $_POST['LeaseTermMonths'] !== '' ? (int)$_POST['LeaseTermMonths'] : 0,
        'Furnishing' => isset($_POST['Furnishing']) && $_POST['Furnishing'] !== '' ? trim($_POST['Furnishing']) : 'Unfurnished',
        'AvailableFrom' => isset($_POST['AvailableFrom']) && $_POST['AvailableFrom'] !== '' ? trim($_POST['AvailableFrom']) : null
    ];
    
    // Check if property is Sold (locked for editing)
    $sold_check = $conn->prepare("SELECT Status FROM property WHERE property_ID = ? LIMIT 1");
    $sold_check->bind_param('i', $property_id);
    $sold_check->execute();
    $current_status = $sold_check->get_result()->fetch_assoc()['Status'] ?? '';
    $sold_check->close();
    if ($current_status === 'Sold') {
        throw new Exception('This property has been sold and is locked for editing.');
    }

    // Whitelist allowed Status values
    $allowed_statuses = ['For Sale', 'For Rent', 'Pending', 'Approved', 'Rejected'];
    if (!in_array($fields['Status'], $allowed_statuses, true)) {
        throw new Exception('Invalid status value.');
    }

    // Basic validation
    if (empty($fields['StreetAddress']) || empty($fields['City']) || empty($fields['PropertyType'])) {
        throw new Exception('Required fields are missing');
    }

    if (!empty($fields['ZIP']) && !preg_match('/^\d{4}$/', $fields['ZIP'])) {
        throw new Exception('ZIP code must be exactly 4 digits.');
    }
    
    // Validate property type against database
    $pt_check = $conn->prepare("SELECT property_type_id FROM property_types WHERE type_name = ? LIMIT 1");
    $pt_check->bind_param("s", $fields['PropertyType']);
    $pt_check->execute();
    if ($pt_check->get_result()->num_rows === 0) {
        throw new Exception('Invalid Property Type selected.');
    }
    $pt_check->close();
    
    if ($fields['ListingPrice'] <= 0) {
        throw new Exception('Listing price must be greater than 0');
    }

    // Validate ListingDate format and no future dates
    if (!empty($fields['ListingDate'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['ListingDate'])) {
            throw new Exception('Invalid listing date format.');
        }
        if ($fields['ListingDate'] > date('Y-m-d')) {
            throw new Exception('Listing date cannot be in the future.');
        }
    }
    
    // Update property
    $sql = "UPDATE property SET 
        StreetAddress = ?,
        City = ?,
        Province = ?,
        ZIP = ?,
        Barangay = ?,
        PropertyType = ?,
        Status = ?,
        YearBuilt = ?,
        Bedrooms = ?,
        Bathrooms = ?,
        ListingDate = ?,
        ListingPrice = ?,
        SquareFootage = ?,
        LotSize = ?,
        ParkingType = ?,
        Source = ?,
        MLSNumber = ?,
        ListingDescription = ?
        WHERE property_ID = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'sssssssiidsdidssssi',
        $fields['StreetAddress'],
        $fields['City'],
        $fields['Province'],
        $fields['ZIP'],
        $fields['Barangay'],
        $fields['PropertyType'],
        $fields['Status'],
        $fields['YearBuilt'],
        $fields['Bedrooms'],
        $fields['Bathrooms'],
        $fields['ListingDate'],
        $fields['ListingPrice'],
        $fields['SquareFootage'],
        $fields['LotSize'],
        $fields['ParkingType'],
        $fields['Source'],
        $fields['MLSNumber'],
        $fields['ListingDescription'],
        $property_id
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update property: ' . $stmt->error);
    }
    $stmt->close();
    
    // Handle rental details separately if property is For Rent
    if ($fields['Status'] === 'For Rent') {
        // Validate required rental fields
        if ($rentalFields['LeaseTermMonths'] <= 0) {
            throw new Exception('Lease term is required for rental properties');
        }
        $validFurnishing = ['Unfurnished', 'Semi-Furnished', 'Fully Furnished'];
        if (!in_array($rentalFields['Furnishing'], $validFurnishing, true)) {
            throw new Exception('Invalid furnishing option. Must be Unfurnished, Semi-Furnished, or Fully Furnished.');
        }
        if (empty($rentalFields['AvailableFrom']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rentalFields['AvailableFrom'])) {
            throw new Exception('A valid available-from date (YYYY-MM-DD) is required for rental properties.');
        }
        
        // Check if rental details exist
        $check_rental = $conn->prepare("SELECT property_id FROM rental_details WHERE property_id = ? LIMIT 1");
        $check_rental->bind_param('i', $property_id);
        $check_rental->execute();
        $rental_exists = $check_rental->get_result()->num_rows > 0;
        $check_rental->close();
        
        if ($rental_exists) {
            // Update existing rental details
            $update_rental = $conn->prepare(
                "UPDATE rental_details SET 
                monthly_rent = ?,
                security_deposit = ?,
                lease_term_months = ?,
                furnishing = ?,
                available_from = ?
                WHERE property_id = ?"
            );
            $update_rental->bind_param(
                'ddissi',
                $fields['ListingPrice'],
                $rentalFields['SecurityDeposit'],
                $rentalFields['LeaseTermMonths'],
                $rentalFields['Furnishing'],
                $rentalFields['AvailableFrom'],
                $property_id
            );
            if (!$update_rental->execute()) {
                throw new Exception('Failed to update rental details: ' . $update_rental->error);
            }
            $update_rental->close();
        } else {
            // Insert new rental details
            $insert_rental = $conn->prepare(
                "INSERT INTO rental_details (property_id, monthly_rent, security_deposit, lease_term_months, furnishing, available_from)
                VALUES (?, ?, ?, ?, ?, ?)"
            );
            $insert_rental->bind_param(
                'iddiss',
                $property_id,
                $fields['ListingPrice'],
                $rentalFields['SecurityDeposit'],
                $rentalFields['LeaseTermMonths'],
                $rentalFields['Furnishing'],
                $rentalFields['AvailableFrom']
            );
            if (!$insert_rental->execute()) {
                throw new Exception('Failed to insert rental details: ' . $insert_rental->error);
            }
            $insert_rental->close();
        }
    }
    
    // Update amenities
    // First, delete existing amenities
    $delete_stmt = $conn->prepare("DELETE FROM property_amenities WHERE property_id = ?");
    $delete_stmt->bind_param('i', $property_id);
    $delete_stmt->execute();
    $delete_stmt->close();
    
    // Insert new amenities
    if (isset($_POST['amenities']) && is_array($_POST['amenities'])) {
        $insert_stmt = $conn->prepare("INSERT INTO property_amenities (property_id, amenity_id) VALUES (?, ?)");
        foreach ($_POST['amenities'] as $amenity_id) {
            $amenity_id = (int)$amenity_id;
            if ($amenity_id > 0) {
                $insert_stmt->bind_param('ii', $property_id, $amenity_id);
                $insert_stmt->execute();
            }
        }
        $insert_stmt->close();
    }
    
    // Log the update action
    $log_stmt = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, log_timestamp) VALUES (?, ?, 'UPDATED', NOW())");
    $log_stmt->bind_param('ii', $property_id, $_SESSION['account_id']);
    $log_stmt->execute();
    $log_stmt->close();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Property updated successfully'
    ]);
    
} catch (Exception $e) {
    if ($conn) {
        $conn->rollback();
    }
    error_log("Property update error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>