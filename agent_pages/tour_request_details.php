<?php
session_start();
include '../connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$agent_account_id = (int)$_SESSION['account_id'];
$tour_id = isset($_POST['tour_id']) ? (int)$_POST['tour_id'] : 0;
$mark_read = isset($_POST['mark_read']) ? (int)$_POST['mark_read'] : 0;

if ($tour_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// Fetch tour details ensuring it belongs to this agent
$sql = "SELECT tr.*, p.StreetAddress, p.City, p.State
        FROM tour_requests tr
        JOIN property p ON tr.property_id = p.property_ID
        WHERE tr.tour_id = ? AND tr.agent_account_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $tour_id, $agent_account_id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Tour not found.']);
    exit;
}

// Optionally mark as read
if ($mark_read === 1 && (int)$req['is_read_by_agent'] === 0) {
    $upd = $conn->prepare("UPDATE tour_requests SET is_read_by_agent = 1 WHERE tour_id = ? AND agent_account_id = ?");
    $upd->bind_param('ii', $tour_id, $agent_account_id);
    $upd->execute();
    $upd->close();
}

$address = htmlspecialchars($req['StreetAddress'] . ', ' . $req['City'] . ', ' . $req['State']);
$user_name = htmlspecialchars($req['user_name']);
$user_email = htmlspecialchars($req['user_email']);
$user_phone = htmlspecialchars($req['user_phone']);
$date = date('F j, Y', strtotime($req['tour_date']));
$time = date('g:i A', strtotime($req['tour_time']));
$message = nl2br(htmlspecialchars($req['message'] ?? ''));
$status = htmlspecialchars($req['request_status']);
$reason = isset($req['decision_reason']) ? trim($req['decision_reason']) : '';

$confirmedAt = isset($req['confirmed_at']) && $req['confirmed_at'] ? date('F j, Y g:i A', strtotime($req['confirmed_at'])) : null;
$completedAt = isset($req['completed_at']) && $req['completed_at'] ? date('F j, Y g:i A', strtotime($req['completed_at'])) : null;
$decisionAt  = isset($req['decision_at']) && $req['decision_at'] ? date('F j, Y g:i A', strtotime($req['decision_at'])) : null;
$decisionBy  = isset($req['decision_by']) ? trim((string)$req['decision_by']) : '';

$html = "
  <div class='card border-0 mb-3'>
    <div class='card-body p-0'>
      <h6 class='text-muted mb-2'><i class='fas fa-home me-2'></i>PROPERTY</h6>
      <p class='mb-3 fw-semibold'>{$address}</p>
      
      <h6 class='text-muted mb-2'><i class='fas fa-user me-2'></i>CLIENT</h6>
      <div class='d-flex flex-column mb-3'>
        <span class='fw-semibold'>{$user_name}</span>
        <span><a href='mailto:{$user_email}' class='text-decoration-none'>{$user_email}</a></span>
        " . ($user_phone?"<span><a href='tel:{$user_phone}' class='text-decoration-none'>{$user_phone}</a></span>":"") . "
      </div>
      
      <h6 class='text-muted mb-2'><i class='fas fa-calendar me-2'></i>REQUESTED SCHEDULE</h6>
      <p class='mb-3 fw-semibold'>{$date} at {$time}</p>
      
  <h6 class='text-muted mb-2'><i class='fas fa-tag me-2'></i>STATUS</h6>
      <p class='mb-3'>" . (
  $status === 'Confirmed' ? "<span class='status-badge status-confirmed'><i class=\"fas fa-check me-1\"></i>Confirmed</span>" :
  ($status === 'Cancelled' ? "<span class='status-badge status-cancelled'><i class=\"fas fa-ban me-1\"></i>Cancelled</span>" :
  ($status === 'Rejected' ? "<span class='status-badge status-rejected'><i class=\"fas fa-ban me-1\"></i>Rejected</span>" :
  ($status === 'Completed' ? "<span class='status-badge status-completed'><i class=\"fas fa-clipboard-check me-1\"></i>Completed</span>" :
        "<span class='status-badge status-pending'><i class=\"fas fa-clock me-1\"></i>Pending</span>")))
      ) . "</p>

  " . ($confirmedAt && ($status === 'Confirmed' || $status === 'Completed')
    ? "<div class='mb-3 small text-muted'><i class='far fa-clock me-1'></i>Confirmed at <strong>{$confirmedAt}</strong></div>"
    : "") . "
  " . ($completedAt && $status === 'Completed'
    ? "<div class='mb-3 small text-muted'><i class='far fa-clock me-1'></i>Completed at <strong>{$completedAt}</strong></div>"
    : "") . "
      
  " . ((($status === 'Cancelled') || ($status === 'Rejected')) && $reason !== ''
            ? "<h6 class='text-muted mb-2'><i class='fas fa-exclamation-triangle me-2'></i>REASON</h6>
               <div class='p-3 bg-light rounded'><em>" . nl2br(htmlspecialchars($reason)) . "</em></div>"
            : "") . "

  " . ((($status === 'Cancelled') || ($status === 'Rejected')) && $decisionAt
    ? "<div class='mt-2 small text-muted'><i class='far fa-clock me-1'></i>Decision at <strong>{$decisionAt}</strong>" . ($decisionBy ? " by <strong>" . htmlspecialchars(ucfirst($decisionBy)) . "</strong>" : "") . "</div>"
    : "") . "

      <h6 class='text-muted mb-2'><i class='fas fa-comment me-2'></i>MESSAGE</h6>
      <div class='p-3 bg-light rounded'>{$message}</div>
    </div>
  </div>
";

echo json_encode(['success' => true, 'html' => $html, 'status' => $req['request_status']]);
