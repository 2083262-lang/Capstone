<?php
session_start();
include '../connection.php';
require_once __DIR__ . '/../config/session_timeout.php';

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
$sql = "SELECT tr.*, p.StreetAddress, p.City, p.Province
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

$address = htmlspecialchars($req['StreetAddress'] . ', ' . $req['City'] . ', ' . $req['Province']);
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
$expiredAt   = isset($req['expired_at']) && $req['expired_at'] ? date('F j, Y g:i A', strtotime($req['expired_at'])) : null;
$decisionAt  = isset($req['decision_at']) && $req['decision_at'] ? date('F j, Y g:i A', strtotime($req['decision_at'])) : null;
$decisionBy  = isset($req['decision_by']) ? trim((string)$req['decision_by']) : '';

$tourType = htmlspecialchars($req['tour_type'] ?? 'private');
$tourTypeBadge = $tourType === 'public'
  ? "<span class='type-badge type-public'><i class='fas fa-users'></i> Public Tour</span>"
  : "<span class='type-badge type-private'><i class='fas fa-user'></i> Private Tour</span>";

$statusClass = strtolower($status);
$statusIcon = (
  $status === 'Confirmed' ? 'fa-check-circle' :
  ($status === 'Cancelled' ? 'fa-times-circle' :
  ($status === 'Rejected' ? 'fa-ban' :
  ($status === 'Completed' ? 'fa-clipboard-check' :
  ($status === 'Expired' ? 'fa-hourglass-end' : 'fa-clock'))))
);

$html = "
  <!-- Status Header -->
  <div class='modal-status-header status-{$statusClass}'>
    <div class='modal-status-label'>Current Status</div>
    <div class='modal-status-row'>
      <div class='modal-status-value'>
        " . (
  $status === 'Confirmed' ? "<span class='status-badge status-confirmed'><i class='fas fa-check me-1'></i>Confirmed</span>" :
  ($status === 'Cancelled' ? "<span class='status-badge status-cancelled'><i class='fas fa-ban me-1'></i>Cancelled</span>" :
  ($status === 'Rejected' ? "<span class='status-badge status-rejected'><i class='fas fa-ban me-1'></i>Rejected</span>" :
  ($status === 'Completed' ? "<span class='status-badge status-completed'><i class='fas fa-clipboard-check me-1'></i>Completed</span>" :
  ($status === 'Expired' ? "<span class='status-badge status-expired'><i class='fas fa-hourglass-end me-1'></i>Expired</span>" :
        "<span class='status-badge status-pending'><i class='fas fa-clock me-1'></i>Pending Response</span>"))))
      ) . "</div>
      <div>{$tourTypeBadge}</div>
    </div>
    " . ($confirmedAt && ($status === 'Confirmed' || $status === 'Completed')
    ? "<div class='timestamp-badge mt-2'><i class='far fa-clock'></i>Confirmed at <strong>{$confirmedAt}</strong></div>"
    : "") . "
    " . ($completedAt && $status === 'Completed'
    ? "<div class='timestamp-badge'><i class='far fa-clock'></i>Completed at <strong>{$completedAt}</strong></div>"
    : "") . "
    " . ($expiredAt && $status === 'Expired'
    ? "<div class='timestamp-badge mt-2'><i class='far fa-clock'></i>Expired at <strong>{$expiredAt}</strong></div>"
    : "") . "
  </div>

  <!-- Details Grid -->
  <div class='details-grid'>
    <!-- Property Card -->
    <div class='detail-card full-width'>
      <div class='detail-card-header'>
        <div class='detail-card-icon'><i class='fas fa-home'></i></div>
        <div class='detail-card-label'>Property Location</div>
      </div>
      <div class='detail-card-content'>
        <strong>{$address}</strong>
      </div>
    </div>

    <!-- Client Card -->
    <div class='detail-card'>
      <div class='detail-card-header'>
        <div class='detail-card-icon'><i class='fas fa-user'></i></div>
        <div class='detail-card-label'>Client Information</div>
      </div>
      <div class='detail-card-content'>
        <div style='font-size:1.05rem; font-weight: 600; margin-bottom: 0.75rem;'>{$user_name}</div>
        <div class='contact-links'>
          <a href='mailto:{$user_email}' class='contact-link'>
            <i class='fas fa-envelope'></i>
            <span>{$user_email}</span>
          </a>
          " . ($user_phone ? "<a href='tel:{$user_phone}' class='contact-link'>
            <i class='fas fa-phone'></i>
            <span>{$user_phone}</span>
          </a>" : "") . "
        </div>
      </div>
    </div>

    <!-- Schedule Card -->
    <div class='detail-card'>
      <div class='detail-card-header'>
        <div class='detail-card-icon'><i class='fas fa-calendar-check'></i></div>
        <div class='detail-card-label'>Requested Schedule</div>
      </div>
      <div class='detail-card-content'>
        <div class='schedule-display'>
          <div class='schedule-item'>
            <i class='fas fa-calendar-day'></i>
            <strong>{$date}</strong>
          </div>
          <div class='schedule-divider'></div>
          <div class='schedule-item'>
            <i class='fas fa-clock'></i>
            <strong>{$time}</strong>
          </div>
        </div>
      </div>
    </div>
  </div>

  " . ((($status === 'Cancelled') || ($status === 'Rejected') || ($status === 'Expired')) && $reason !== ''
            ? "<div class='section-divider'></div>
               <div class='detail-card full-width'>
                 <div class='detail-card-header'>
                   <div class='detail-card-icon' style='background: rgba(" . ($status === 'Expired' ? '156, 163, 175' : '239, 68, 68') . ", 0.1); color: " . ($status === 'Expired' ? '#9ca3af' : 'var(--danger)') . ";'><i class='fas " . ($status === 'Expired' ? 'fa-hourglass-end' : 'fa-exclamation-triangle') . "'></i></div>
                   <div class='detail-card-label'>" . ($status === 'Expired' ? 'Expiration Reason' : ($status === 'Cancelled' ? 'Reason for Cancellation' : 'Reason for Rejection')) . "</div>
                 </div>
                 <div class='detail-card-content'>
                   <div class='reason-box'>" . nl2br(htmlspecialchars($reason)) . "</div>
                   " . ($decisionAt
    ? "<div class='timestamp-badge'><i class='far fa-clock'></i>" . ($status === 'Expired' ? 'Expired' : 'Decision made') . " at <strong>{$decisionAt}</strong>" . ($decisionBy ? " by <strong>" . htmlspecialchars(ucfirst($decisionBy)) . "</strong>" : "") . "</div>"
    : "") . "
                 </div>
               </div>"
            : "") . "

  " . ($status === 'Expired'
            ? "<div class='section-divider'></div>
               <div class='detail-card full-width'>
                 <div class='detail-card-header'>
                   <div class='detail-card-icon' style='background: rgba(156, 163, 175, 0.1); color: #9ca3af;'><i class='fas fa-hourglass-end'></i></div>
                   <div class='detail-card-label'>Expiration Notice</div>
                 </div>
                 <div class='detail-card-content'>
                   <div class='reason-box' style='border-left-color: #9ca3af;'>This tour request expired automatically because the scheduled date and time passed without a response from the agent. The client has been notified via email.</div>
                   " . ($expiredAt
    ? "<div class='timestamp-badge'><i class='far fa-clock'></i>Auto-expired at <strong>{$expiredAt}</strong> <span style='color:#9ca3af;'>(Philippine Time)</span></div>"
    : "") . "
                 </div>
               </div>"
            : "") . "

  <div class='section-divider'></div>

  <!-- Message Card -->
  <div class='detail-card full-width'>
    <div class='detail-card-header'>
      <div class='detail-card-icon'><i class='fas fa-comment-dots'></i></div>
      <div class='detail-card-label'>Client Message</div>
    </div>
    <div class='detail-card-content'>
      <div class='message-box'>{$message}</div>
    </div>
  </div>
";

echo json_encode(['success' => true, 'html' => $html, 'status' => $req['request_status']]);
