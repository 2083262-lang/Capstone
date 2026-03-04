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

$property_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($property_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid property ID']);
    exit;
}

try {
    // Fetch property data with rental details
    $stmt = $conn->prepare("
        SELECT p.*, 
               rd.monthly_rent AS MonthlyRent,
               rd.security_deposit AS SecurityDeposit,
               rd.lease_term_months AS LeaseTermMonths,
               rd.furnishing AS Furnishing,
               rd.available_from AS AvailableFrom
        FROM property p
        LEFT JOIN rental_details rd ON p.property_ID = rd.property_id
        WHERE p.property_ID = ? 
        LIMIT 1
    ");
    $stmt->bind_param('i', $property_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Property not found']);
        exit;
    }
    
    $property = $result->fetch_assoc();
    $stmt->close();
    
    // Fetch all amenities
    $amenities_result = $conn->query("SELECT amenity_id, amenity_name FROM amenities ORDER BY amenity_name");
    $amenities = [];
    while ($row = $amenities_result->fetch_assoc()) {
        $amenities[] = $row;
    }
    
    // Fetch selected amenities for this property
    $stmt = $conn->prepare("SELECT amenity_id FROM property_amenities WHERE property_id = ?");
    $stmt->bind_param('i', $property_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $selectedAmenities = [];
    while ($row = $result->fetch_assoc()) {
        $selectedAmenities[] = (int)$row['amenity_id'];
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'property' => $property,
        'amenities' => $amenities,
        'selectedAmenities' => $selectedAmenities
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
