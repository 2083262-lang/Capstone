<?php
session_start();
header('Content-Type: application/json');
include '../connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$agent_account_id = $_SESSION['account_id'];
$tour_id = isset($_POST['tour_id']) ? (int)$_POST['tour_id'] : 0;

if ($tour_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid tour ID']);
    exit();
}

// Get the tour details
$sql = "SELECT tour_date, tour_time, property_id, tour_type FROM tour_requests WHERE tour_id = ? AND agent_account_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $tour_id, $agent_account_id);
$stmt->execute();
$result = $stmt->get_result();
$tour = $result->fetch_assoc();
$stmt->close();

if (!$tour) {
    echo json_encode(['success' => false, 'message' => 'Tour not found']);
    exit();
}

$tour_date = $tour['tour_date'];
$tour_time = $tour['tour_time'];
$property_id = $tour['property_id'];

// Check for conflicts on the same date (warn only)
$sql_same_day = "
    SELECT COUNT(*) as count
    FROM tour_requests
    WHERE agent_account_id = ?
    AND tour_date = ?
    AND tour_id != ?
    AND request_status IN ('Pending', 'Confirmed')
";
$stmt = $conn->prepare($sql_same_day);
$stmt->bind_param('isi', $agent_account_id, $tour_date, $tour_id);
$stmt->execute();
$same_day_result = $stmt->get_result()->fetch_assoc();
$same_day_count = (int)$same_day_result['count'];
$stmt->close();

// Check for exact time conflicts (block only if another is already Confirmed)
$sql_exact_time = "
    SELECT tr.*, p.StreetAddress, p.City
    FROM tour_requests tr
    LEFT JOIN property p ON p.property_ID = tr.property_id
    WHERE tr.agent_account_id = ?
    AND tr.tour_date = ?
    AND tr.tour_time = ?
    AND tr.tour_id != ?
    AND tr.request_status = 'Confirmed'
";
$stmt = $conn->prepare($sql_exact_time);
$stmt->bind_param('issi', $agent_account_id, $tour_date, $tour_time, $tour_id);
$stmt->execute();
$exact_conflicts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

$has_exact_conflict = false;
$same_property_public_count = 0;
if (!empty($exact_conflicts)) {
    foreach ($exact_conflicts as $row) {
        // Private requests conflict with any confirmed at same slot
        if ($tour['tour_type'] === 'private') {
            $has_exact_conflict = true;
            break;
        }
        // Public requests only conflict with confirmed private, or confirmed public for a different property
        if ($tour['tour_type'] === 'public') {
            if ($row['tour_type'] === 'private' || (int)$row['property_id'] !== (int)$tour['property_id']) {
                $has_exact_conflict = true;
                break;
            }
            if ($row['tour_type'] === 'public' && (int)$row['property_id'] === (int)$tour['property_id']) {
                $same_property_public_count++;
            }
        }
    }
}
$has_same_day = $same_day_count > 0;

$response = [
    'success' => true,
    'has_exact_conflict' => $has_exact_conflict,
    'has_same_day_conflict' => $has_same_day,
    'same_day_count' => $same_day_count,
    'conflicts' => $exact_conflicts,
    'can_confirm' => !$has_exact_conflict
];

if ($has_exact_conflict) {
    $conflict = $exact_conflicts[0];
    $response['message'] = 'Cannot confirm: Another tour is already CONFIRMED at ' . date('g:i A', strtotime($tour_time)) . ' on ' . date('M j, Y', strtotime($tour_date)) . ' for ' . htmlspecialchars($conflict['StreetAddress']) . '.';
} elseif ($has_same_day) {
    $response['message'] = 'Warning: ' . $same_day_count . ' other tour(s) already scheduled on ' . date('M j, Y', strtotime($tour_date)) . '. Please verify times to avoid conflicts.';
}

// Inform about grouped public tours at the same property/time (non-blocking)
if ($tour['tour_type'] === 'public' && !$has_exact_conflict && $same_property_public_count > 0) {
    $response['group_public_notice'] = true;
    $response['group_public_count'] = $same_property_public_count;
    $response['group_public_message'] = 'Note: ' . $same_property_public_count . ' other public tour request(s) for this same property, date, and time are already CONFIRMED. This request will be grouped with them.';
}

echo json_encode($response);
?>
