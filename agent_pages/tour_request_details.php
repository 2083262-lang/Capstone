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

$tourType = htmlspecialchars($req['tour_type'] ?? 'private');
$tourTypeBadge = $tourType === 'public'
  ? "<span class='type-badge type-public'><i class='fas fa-users'></i> Public Tour</span>"
  : "<span class='type-badge type-private'><i class='fas fa-user'></i> Private Tour</span>";

$html = "
  <div class='detail-section'>
    <div class='detail-section-label'><i class='fas fa-home'></i> Property</div>
    <div class='detail-section-value'>{$address}</div>
  </div>

  <div class='detail-section'>
    <div class='detail-section-label'><i class='fas fa-user'></i> Client</div>
    <div class='detail-section-value'>
      <div style='font-size:1.05rem;'>{$user_name}</div>
      <div style='margin-top:0.25rem;'><a href='mailto:{$user_email}'><i class='fas fa-envelope me-1' style='font-size:0.75rem;'></i>{$user_email}</a></div>
      " . ($user_phone ? "<div style='margin-top:0.15rem;'><a href='tel:{$user_phone}'><i class='fas fa-phone me-1' style='font-size:0.75rem;'></i>{$user_phone}</a></div>" : "") . "
    </div>
  </div>

  <div class='detail-section'>
    <div class='detail-section-label'><i class='fas fa-calendar'></i> Requested Schedule</div>
    <div class='detail-section-value'>{$date} at {$time}</div>
  </div>

  <div class='detail-section'>
    <div class='detail-section-label'><i class='fas fa-route'></i> Tour Type</div>
    <div class='detail-section-value'>{$tourTypeBadge}</div>
  </div>

  <div class='detail-section'>
    <div class='detail-section-label'><i class='fas fa-tag'></i> Status</div>
    <div class='detail-section-value'>" . (
  $status === 'Confirmed' ? "<span class='status-badge status-confirmed'><i class=\"fas fa-check me-1\"></i>Confirmed</span>" :
  ($status === 'Cancelled' ? "<span class='status-badge status-cancelled'><i class=\"fas fa-ban me-1\"></i>Cancelled</span>" :
  ($status === 'Rejected' ? "<span class='status-badge status-rejected'><i class=\"fas fa-ban me-1\"></i>Rejected</span>" :
  ($status === 'Completed' ? "<span class='status-badge status-completed'><i class=\"fas fa-clipboard-check me-1\"></i>Completed</span>" :
        "<span class='status-badge status-pending'><i class=\"fas fa-clock me-1\"></i>Pending</span>")))
      ) . "</div>
  </div>

  " . ($confirmedAt && ($status === 'Confirmed' || $status === 'Completed')
    ? "<div class='timestamp-info mb-3'><i class='far fa-clock me-1'></i>Confirmed at <strong>{$confirmedAt}</strong></div>"
    : "") . "
  " . ($completedAt && $status === 'Completed'
    ? "<div class='timestamp-info mb-3'><i class='far fa-clock me-1'></i>Completed at <strong>{$completedAt}</strong></div>"
    : "") . "
      
  " . ((($status === 'Cancelled') || ($status === 'Rejected')) && $reason !== ''
            ? "<div class='detail-section'>
                 <div class='detail-section-label'><i class='fas fa-exclamation-triangle'></i> Reason</div>
                 <div class='reason-box'><em>" . nl2br(htmlspecialchars($reason)) . "</em></div>
               </div>"
            : "") . "

  " . ((($status === 'Cancelled') || ($status === 'Rejected')) && $decisionAt
    ? "<div class='timestamp-info mb-3'><i class='far fa-clock me-1'></i>Decision at <strong>{$decisionAt}</strong>" . ($decisionBy ? " by <strong>" . htmlspecialchars(ucfirst($decisionBy)) . "</strong>" : "") . "</div>"
    : "") . "

  <div class='detail-section'>
    <div class='detail-section-label'><i class='fas fa-comment'></i> Client Message</div>
    <div class='message-box'>{$message}</div>
  </div>
";

echo json_encode(['success' => true, 'html' => $html, 'status' => $req['request_status']]);
