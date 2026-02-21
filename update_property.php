<?php
session_start();
require_once 'connection.php';

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
        'State' => trim($_POST['State'] ?? ''),
        'ZIP' => trim($_POST['ZIP'] ?? ''),
        'County' => trim($_POST['County'] ?? ''),
        'PropertyType' => trim($_POST['PropertyType'] ?? ''),
        'Status' => trim($_POST['Status'] ?? ''),
        'YearBuilt' => isset($_POST['YearBuilt']) ? (int)$_POST['YearBuilt'] : 0,
        'Bedrooms' => isset($_POST['Bedrooms']) ? (int)$_POST['Bedrooms'] : 0,
        'Bathrooms' => isset($_POST['Bathrooms']) ? (float)$_POST['Bathrooms'] : 0,
        'ListingDate' => trim($_POST['ListingDate'] ?? ''),
        'ListingPrice' => isset($_POST['ListingPrice']) ? (float)$_POST['ListingPrice'] : 0,
        'SquareFootage' => isset($_POST['SquareFootage']) ? (int)$_POST['SquareFootage'] : 0,
        'LotSize' => isset($_POST['LotSize']) ? (float)$_POST['LotSize'] : 0,
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
    
    // Basic validation
    if (empty($fields['StreetAddress']) || empty($fields['City']) || empty($fields['PropertyType'])) {
        throw new Exception('Required fields are missing');
    }
    
    if ($fields['ListingPrice'] <= 0) {
        throw new Exception('Listing price must be greater than 0');
    }
    
    // Update property
    $sql = "UPDATE property SET 
        StreetAddress = ?,
        City = ?,
        State = ?,
        ZIP = ?,
        County = ?,
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
        $fields['State'],
        $fields['ZIP'],
        $fields['County'],
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
        if (empty($rentalFields['Furnishing'])) {
            throw new Exception('Furnishing status is required for rental properties');
        }
        if (empty($rentalFields['AvailableFrom'])) {
            throw new Exception('Available from date is required for rental properties');
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