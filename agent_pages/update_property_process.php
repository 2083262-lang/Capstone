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

// Update amenities if provided
if (isset($_POST['amenities']) && is_array($_POST['amenities'])) {
    // Delete existing amenities
    $del_am = $conn->prepare("DELETE FROM property_amenities WHERE property_id = ?");
    $del_am->bind_param('i', $pid);
    $del_am->execute();
    $del_am->close();

    // Insert new amenities
    $ins_am = $conn->prepare("INSERT INTO property_amenities (property_id, amenity_id) VALUES (?, ?)");
    foreach ($_POST['amenities'] as $amenity_id) {
        $aid = (int)$amenity_id;
        if ($aid > 0) {
            $ins_am->bind_param('ii', $pid, $aid);
            $ins_am->execute();
        }
    }
    $ins_am->close();
} else {
    // No amenities selected — clear all
    $del_am = $conn->prepare("DELETE FROM property_amenities WHERE property_id = ?");
    $del_am->bind_param('i', $pid);
    $del_am->execute();
    $del_am->close();
}

// ========================================================================
// Process photo and floor image deletions (deferred from frontend)
// ========================================================================

// Handle deleted featured photos
if (isset($_POST['deleted_photos']) && !empty($_POST['deleted_photos'])) {
    $deletedPhotos = json_decode($_POST['deleted_photos'], true);
    if (is_array($deletedPhotos) && count($deletedPhotos) > 0) {
        foreach ($deletedPhotos as $photoUrl) {
            if (empty($photoUrl)) continue;
            
            // Delete from database
            $del_photo = $conn->prepare("DELETE FROM property_images WHERE property_ID = ? AND PhotoURL = ?");
            $del_photo->bind_param('is', $pid, $photoUrl);
            $del_photo->execute();
            $del_photo->close();
            
            // Delete physical file
            $fsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photoUrl);
            if (is_file($fsPath)) {
                @unlink($fsPath);
            }
        }
        
        // Re-normalize sort order for remaining photos
        $photos_list = $conn->prepare('SELECT PhotoID FROM property_images WHERE property_ID = ? ORDER BY SortOrder ASC');
        $photos_list->bind_param('i', $pid);
        $photos_list->execute();
        $photo_rows = $photos_list->get_result()->fetch_all(MYSQLI_ASSOC);
        $photos_list->close();
        
        $order = 1;
        foreach ($photo_rows as $pr) {
            $upd_order = $conn->prepare('UPDATE property_images SET SortOrder = ? WHERE PhotoID = ?');
            $upd_order->bind_param('ii', $order, $pr['PhotoID']);
            $upd_order->execute();
            $upd_order->close();
            $order++;
        }
    }
}

// Handle deleted floor images
if (isset($_POST['deleted_floor_images']) && !empty($_POST['deleted_floor_images'])) {
    $deletedFloorImages = json_decode($_POST['deleted_floor_images'], true);
    if (is_array($deletedFloorImages) && count($deletedFloorImages) > 0) {
        foreach ($deletedFloorImages as $floorImg) {
            if (!isset($floorImg['floor_number']) || !isset($floorImg['photo_url'])) continue;
            
            $floorNum = (int)$floorImg['floor_number'];
            $photoUrl = trim($floorImg['photo_url']);
            if ($floorNum < 1 || empty($photoUrl)) continue;
            
            // Normalize path
            $photoUrl = str_replace(['../', './'], '', $photoUrl);
            $photoUrl = ltrim($photoUrl, '/');
            
            // Delete from database (use LIKE for flexible matching)
            $likePattern = '%' . basename($photoUrl);
            $del_floor = $conn->prepare("DELETE FROM property_floor_images WHERE property_id = ? AND floor_number = ? AND (photo_url = ? OR photo_url LIKE ?)");
            $del_floor->bind_param('iiss', $pid, $floorNum, $photoUrl, $likePattern);
            $del_floor->execute();
            $del_floor->close();
            
            // Delete physical file
            $fsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photoUrl);
            if (is_file($fsPath)) {
                @unlink($fsPath);
            }
            
            // Re-normalize sort order for this floor
            $floor_imgs = $conn->prepare('SELECT id FROM property_floor_images WHERE property_id = ? AND floor_number = ? ORDER BY sort_order ASC');
            $floor_imgs->bind_param('ii', $pid, $floorNum);
            $floor_imgs->execute();
            $floor_list = $floor_imgs->get_result()->fetch_all(MYSQLI_ASSOC);
            $floor_imgs->close();
            
            $i = 1;
            foreach ($floor_list as $item) {
                $upd_floor = $conn->prepare('UPDATE property_floor_images SET sort_order = ? WHERE id = ?');
                $upd_floor->bind_param('ii', $i, $item['id']);
                $upd_floor->execute();
                $upd_floor->close();
                $i++;
            }
        }
    }
}

// Handle removed entire floors
if (isset($_POST['removed_floors']) && !empty($_POST['removed_floors'])) {
    $removedFloors = json_decode($_POST['removed_floors'], true);
    if (is_array($removedFloors) && count($removedFloors) > 0) {
        foreach ($removedFloors as $floorNum) {
            $floorNum = (int)$floorNum;
            if ($floorNum < 1) continue;
            
            // Get all images for this floor to delete physical files
            $get_imgs = $conn->prepare('SELECT photo_url FROM property_floor_images WHERE property_id = ? AND floor_number = ?');
            $get_imgs->bind_param('ii', $pid, $floorNum);
            $get_imgs->execute();
            $imgs_result = $get_imgs->get_result();
            
            while ($img_row = $imgs_result->fetch_assoc()) {
                $fsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $img_row['photo_url']);
                if (is_file($fsPath)) {
                    @unlink($fsPath);
                }
            }
            $get_imgs->close();
            
            // Delete all images for this floor from database
            $del_floor_all = $conn->prepare('DELETE FROM property_floor_images WHERE property_id = ? AND floor_number = ?');
            $del_floor_all->bind_param('ii', $pid, $floorNum);
            $del_floor_all->execute();
            $del_floor_all->close();
            
            // Try to remove the floor directory if empty
            $floorDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'floors' . DIRECTORY_SEPARATOR . $pid . DIRECTORY_SEPARATOR . 'floor_' . $floorNum;
            if (is_dir($floorDir)) {
                @rmdir($floorDir);
            }
        }
    }
}

echo json_encode(['success' => true, 'message' => 'Property updated successfully.']);
?>