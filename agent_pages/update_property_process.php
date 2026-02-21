<?php
session_start();
header('Content-Type: application/json');
include '../connection.php';

// Auth check
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

// Helper
function jfail($msg, $errors = []) { echo json_encode(['success' => false, 'message' => $msg, 'errors' => $errors]); exit; }

$agent_id = (int)$_SESSION['account_id'];

// Collect inputs
$fields = [
  'property_id' => FILTER_SANITIZE_NUMBER_INT,
  'StreetAddress' => FILTER_UNSAFE_RAW,
  'City' => FILTER_UNSAFE_RAW,
  'State' => FILTER_UNSAFE_RAW,
  'ZIP' => FILTER_UNSAFE_RAW,
  'ListingPrice' => FILTER_UNSAFE_RAW,
  'ListingDate' => FILTER_UNSAFE_RAW,
  'Bedrooms' => FILTER_UNSAFE_RAW,
  'Bathrooms' => FILTER_UNSAFE_RAW,
  'SquareFootage' => FILTER_UNSAFE_RAW,
  'YearBuilt' => FILTER_UNSAFE_RAW,
  'LotSize' => FILTER_UNSAFE_RAW,
  'PropertyType' => FILTER_UNSAFE_RAW,
  'ParkingType' => FILTER_UNSAFE_RAW,
  'MLSNumber' => FILTER_UNSAFE_RAW,
  'County' => FILTER_UNSAFE_RAW,
  'Source' => FILTER_UNSAFE_RAW,
  'ListingDescription' => FILTER_UNSAFE_RAW,
];
$input = [];
foreach ($fields as $k => $flt) { $input[$k] = isset($_POST[$k]) ? trim($_POST[$k]) : null; }

$errors = [];

// Basic validations
$pid = (int)$input['property_id'];
if ($pid <= 0) $errors['property_id'] = 'Invalid property.';

if ($input['StreetAddress'] === '') $errors['StreetAddress'] = 'Street address is required.';
if ($input['City'] === '') $errors['City'] = 'City is required.';
if ($input['State'] === '') $errors['State'] = 'State is required.';
if ($input['ZIP'] === '') $errors['ZIP'] = 'ZIP is required.';

$price = is_numeric($input['ListingPrice']) ? (float)$input['ListingPrice'] : -1;
if ($price < 0) $errors['ListingPrice'] = 'Listing price must be a positive number.';

$date = $input['ListingDate'];
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
  $errors['ListingDate'] = 'Listing date is invalid or in the future.';
}

$beds = is_numeric($input['Bedrooms']) ? (int)$input['Bedrooms'] : -1;
if ($beds < 0) $errors['Bedrooms'] = 'Bedrooms must be 0 or more.';

$baths = is_numeric($input['Bathrooms']) ? (float)$input['Bathrooms'] : -1;
if ($baths < 0) $errors['Bathrooms'] = 'Bathrooms must be 0 or more.';

$sqft = is_numeric($input['SquareFootage']) ? (int)$input['SquareFootage'] : -1;
if ($sqft < 0) $errors['SquareFootage'] = 'Square footage must be 0 or more.';

if ($input['YearBuilt'] !== '' && (!is_numeric($input['YearBuilt']) || (int)$input['YearBuilt'] < 1800 || (int)$input['YearBuilt'] > (int)date('Y'))) {
  $errors['YearBuilt'] = 'Year built must be between 1800 and the current year.';
}

if (strlen($input['ListingDescription']) < 20) $errors['ListingDescription'] = 'Description must be at least 20 characters.';

if ($errors) jfail('Please correct the highlighted fields.', $errors);

// Verify ownership
$sql_owner = 'SELECT 1 FROM property_log WHERE property_id = ? AND account_id = ? LIMIT 1';
$st = $conn->prepare($sql_owner);
if (!$st) jfail('Database error: '.$conn->error);
$st->bind_param('ii', $pid, $agent_id);
$st->execute();
$is_owner = $st->get_result()->num_rows > 0;
$st->close();
if (!$is_owner) jfail('You do not have permission to update this property.');

// Check if property is sold (locked for editing)
$sql_check_sold = 'SELECT Status FROM property WHERE property_ID = ?';
$st_check = $conn->prepare($sql_check_sold);
$st_check->bind_param('i', $pid);
$st_check->execute();
$result_check = $st_check->get_result();
$property_status = $result_check->fetch_assoc()['Status'];
$st_check->close();

if ($property_status === 'Sold') {
    jfail('This property has been sold and is locked for editing to maintain historical accuracy.');
}

// Perform update
$sql = 'UPDATE property SET StreetAddress=?, City=?, State=?, ZIP=?, ListingPrice=?, ListingDate=?, Bedrooms=?, Bathrooms=?, SquareFootage=?, YearBuilt=?, LotSize=?, PropertyType=?, ParkingType=?, MLSNumber=?, County=?, Source=?, ListingDescription=? WHERE property_ID=?';
$stmt = $conn->prepare($sql);
if (!$stmt) jfail('Database error: '.$conn->error);

$yearBuilt = ($input['YearBuilt'] === '' ? null : (int)$input['YearBuilt']);
$lotSize = ($input['LotSize'] === '' ? null : (float)$input['LotSize']);

$stmt->bind_param(
  'ssssdsididsssssssi',
  $input['StreetAddress'],
  $input['City'],
  $input['State'],
  $input['ZIP'],
  $price,
  $date,
  $beds,
  $baths,
  $sqft,
  $yearBuilt,
  $lotSize,
  $input['PropertyType'],
  $input['ParkingType'],
  $input['MLSNumber'],
  $input['County'],
  $input['Source'],
  $input['ListingDescription'],
  $pid
);

if (!$stmt->execute()) {
  $stmt->close();
  jfail('Failed to update property: '.$conn->error);
}
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Property updated successfully.']);
?>