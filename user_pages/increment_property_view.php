<?php
// Endpoint: increment_property_view.php
// Expects POST: property_id
// Returns JSON { success: bool, views: int }

header('Content-Type: application/json');
require_once '../connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$property_id = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
if ($property_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid property id']);
    exit;
}

// Atomically increment ViewsCount and return new value
try {
    $conn->begin_transaction();

    $upd = $conn->prepare("UPDATE property SET ViewsCount = COALESCE(ViewsCount,0) + 1 WHERE property_ID = ?");
    $upd->bind_param('i', $property_id);
    if (!$upd->execute()) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to increment']);
        exit;
    }
    $upd->close();

    $sel = $conn->prepare("SELECT COALESCE(ViewsCount,0) AS cnt FROM property WHERE property_ID = ?");
    $sel->bind_param('i', $property_id);
    $sel->execute();
    $res = $sel->get_result();
    $row = $res->fetch_assoc();
    $sel->close();

    $conn->commit();

    $views = isset($row['cnt']) ? (int)$row['cnt'] : 0;
    echo json_encode(['success' => true, 'views' => $views]);
    exit;
} catch (Exception $e) {
    if ($conn->errno) $conn->rollback();
    error_log('increment_property_view error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}
