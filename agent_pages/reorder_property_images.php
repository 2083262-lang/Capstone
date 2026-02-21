<?php
session_start();
header('Content-Type: application/json');
require_once '../connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid method']); exit(); }

$accountId = (int)$_SESSION['account_id'];
$propertyId = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
$orderJson = $_POST['order'] ?? '[]';
$urls = json_decode($orderJson, true);

if ($propertyId<=0 || !is_array($urls)) { echo json_encode(['success'=>false,'message'=>'Bad params']); exit(); }

// Ownership check
$stmt = $conn->prepare('SELECT 1 FROM property_log WHERE property_id=? AND account_id=? LIMIT 1');
$stmt->bind_param('ii', $propertyId, $accountId);
$stmt->execute();
$own = $stmt->get_result()->num_rows > 0;
$stmt->close();
if (!$own) { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

// Check if property is sold
$stmt = $conn->prepare('SELECT Status FROM property WHERE property_ID=? LIMIT 1');
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
if ($row && $row['Status'] === 'Sold') {
    echo json_encode(['success' => false, 'message' => 'Cannot reorder images for sold properties']);
    exit();
}

// Ensure all urls belong to this property and dedupe
$urls = array_values(array_unique(array_map('strval', $urls)));
if (empty($urls)) { echo json_encode(['success'=>false,'message'=>'Empty order']); exit(); }

// Fetch existing images
$q = $conn->prepare('SELECT PhotoURL FROM property_images WHERE property_ID=?');
$q->bind_param('i', $propertyId);
$q->execute();
$existing = array_column($q->get_result()->fetch_all(MYSQLI_ASSOC), 'PhotoURL');
$q->close();

// Validate URLs set is subset of existing
$setDiff = array_diff($urls, $existing);
if (!empty($setDiff)) { echo json_encode(['success'=>false,'message'=>'Invalid image(s) in order']); exit(); }

// Transactionally update sort order
$conn->begin_transaction();
try {
    $pos = 1;
    foreach ($urls as $u) {
        $upd = $conn->prepare('UPDATE property_images SET SortOrder=? WHERE property_ID=? AND PhotoURL=?');
        $upd->bind_param('iis', $pos, $propertyId, $u);
        if (!$upd->execute()) { throw new Exception('Update failed'); }
        $upd->close();
        $pos++;
    }

    // Any remaining images (not present in urls) keep order after provided ones, sorted by current SortOrder
    $remaining = array_values(array_diff($existing, $urls));
    if (!empty($remaining)) {
        // sort remaining by current SortOrder
        $in = str_repeat('?,', count($remaining)-1) . '?';
        // Build dynamic query safely
        $types = str_repeat('s', count($remaining));
        $sql = "SELECT PhotoURL FROM property_images WHERE property_ID=? AND PhotoURL IN ($in) ORDER BY SortOrder ASC";
        $stmt = $conn->prepare($sql);
        $bindTypes = 'i' . $types;
        $bindParams = array_merge([$bindTypes, $propertyId], $remaining);
        // bind_param with dynamic params
        $refs = [];
        foreach ($bindParams as $key => $value) { $refs[$key] = &$bindParams[$key]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) {
            $upd = $conn->prepare('UPDATE property_images SET SortOrder=? WHERE property_ID=? AND PhotoURL=?');
            $upd->bind_param('iis', $pos, $propertyId, $r['PhotoURL']);
            if (!$upd->execute()) { throw new Exception('Update failed'); }
            $upd->close();
            $pos++;
        }
    }

    $conn->commit();
    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['success'=>false,'message'=>'Transaction failed']);
}
exit();
