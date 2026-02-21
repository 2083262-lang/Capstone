<?php
session_start();
include '../connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header('Location: ../login.php');
    exit();
}

$agent_account_id = $_SESSION['account_id'];
$agent_username = $_SESSION['username'];

// Filter: property_id optional
$filter_property_id = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;

// Get agent info for navbar avatar
$agent_info_sql = "SELECT ai.profile_picture_url FROM agent_information ai WHERE ai.account_id = ?";
$stmt = $conn->prepare($agent_info_sql);
$stmt->bind_param('i', $agent_account_id);
$stmt->execute();
$agent_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch agent properties for filter dropdown
$properties_sql = "
    SELECT p.property_ID, CONCAT(p.StreetAddress, ', ', p.City) AS title
    FROM property p
    JOIN property_log pl ON p.property_ID = pl.property_id
    WHERE pl.account_id = ? AND pl.action = 'CREATED'
    ORDER BY p.ListingDate DESC";
$stmt = $conn->prepare($properties_sql);
$stmt->bind_param('i', $agent_account_id);
$stmt->execute();
$properties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build SQL for tour requests
$tours_sql = "
    SELECT tr.*, p.StreetAddress, p.City
    FROM tour_requests tr
    JOIN property p ON tr.property_id = p.property_ID
    WHERE tr.agent_account_id = ?";
$params = [$agent_account_id];
$types = 'i';

if ($filter_property_id > 0) {
    $tours_sql .= " AND tr.property_id = ?";
    $params[] = $filter_property_id;
    $types .= 'i';
}

$tours_sql .= " ORDER BY tr.requested_at DESC";
$stmt = $conn->prepare($tours_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$tour_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status filtering (All, Pending, Confirmed, Completed, Cancelled, Rejected)
$valid_statuses = ['All','Pending','Confirmed','Completed','Cancelled','Rejected'];
$active_status = isset($_GET['status']) ? ucfirst(strtolower(trim($_GET['status']))) : 'All';
if (!in_array($active_status, $valid_statuses, true)) {
  $active_status = 'All';
}

// Group requests and compute counts
$requests_by_status = [
  'Pending' => [],
  'Confirmed' => [],
  'Completed' => [],
  'Cancelled' => [],
  'Rejected' => [],
];
foreach ($tour_requests as $req) {
  $st = $req['request_status'];
  if (isset($requests_by_status[$st])) {
    $requests_by_status[$st][] = $req;
  }
}

$counts = [
  'All' => count($tour_requests),
  'Pending' => count($requests_by_status['Pending']),
  'Confirmed' => count($requests_by_status['Confirmed']),
  'Completed' => count($requests_by_status['Completed']),
  'Cancelled' => count($requests_by_status['Cancelled']),
  'Rejected' => count($requests_by_status['Rejected']),
];

// Decide which list to display
switch ($active_status) {
  case 'Pending':
  case 'Confirmed':
  case 'Completed':
  case 'Cancelled':
  case 'Rejected':
    $display_requests = $requests_by_status[$active_status];
    break;
  default:
    $display_requests = $tour_requests;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Requested Tours - Agent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root {
      --primary-color: #161209;
      --secondary-color: #bc9e42;
      --background-color: #f8f4f4;
      --card-bg-color: #ffffff;
      --border-color: #e6e6e6;
      --text-muted: #6c757d;
      --shadow-light: 0 2px 8px rgba(0,0,0,0.05);
      --shadow-medium: 0 4px 12px rgba(0,0,0,0.1);
      --shadow-heavy: 0 8px 32px rgba(0,0,0,0.15);
      --success-color: #198754;
      --warning-color: #ffc107;
      --danger-color: #dc3545;
      --info-color: #0dcaf0;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, var(--background-color) 0%, #f0ebe6 100%);
      margin: 0;
      min-height: 100vh;
    }
    
    /* Main Content */
    .content {
      padding: 2rem;
      max-width: 1600px;
      margin: 0 auto;
    }
    
    /* Page Header */
    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 2rem;
      padding: 1.5rem 2rem;
      background: linear-gradient(135deg, var(--card-bg-color) 0%, #fafafa 100%);
      border-radius: 16px;
      box-shadow: var(--shadow-medium);
      border: 1px solid var(--border-color);
    }
    
    .page-title {
      font-weight: 800;
      font-size: 2rem;
      color: var(--primary-color);
      margin-bottom: 0;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    
    .page-title::before {
      content: '';
      width: 4px;
      height: 2rem;
      background: linear-gradient(135deg, var(--secondary-color), #d4b555);
      border-radius: 2px;
    }
    
    .btn-brand {
      background: linear-gradient(135deg, var(--secondary-color), #d4b555);
      border: none;
      color: var(--primary-color);
      font-weight: 600;
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(188, 158, 66, 0.2);
    }
    
    .btn-brand:hover {
      background: linear-gradient(135deg, #d4b555, var(--secondary-color));
      color: var(--primary-color);
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(188, 158, 66, 0.3);
    }
    
    .btn-back {
      background: rgba(255, 255, 255, 0.8);
      border: 1px solid var(--border-color);
      color: var(--text-muted);
      font-weight: 500;
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      transition: all 0.3s ease;
      backdrop-filter: blur(10px);
    }
    
    .btn-back:hover {
      background: var(--card-bg-color);
      color: var(--primary-color);
      transform: translateY(-1px);
      box-shadow: var(--shadow-light);
    }
    
    /* Filter Panel */
    .filter-bar {
      background: linear-gradient(135deg, var(--card-bg-color) 0%, #fafafa 100%);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: var(--shadow-medium);
      position: relative;
      overflow: hidden;
    }
    
    .filter-bar::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--secondary-color), #d4b555, var(--secondary-color));
    }
    
    .filter-title {
      color: var(--primary-color);
      font-weight: 700;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .form-select {
      border: 2px solid var(--border-color);
      border-radius: 12px;
      padding: 0.75rem 1rem;
      font-weight: 500;
      transition: all 0.3s ease;
      background: var(--card-bg-color);
    }
    
    .form-select:focus {
      border-color: var(--secondary-color);
      box-shadow: 0 0 0 0.2rem rgba(188, 158, 66, 0.15);
    }
    
    /* Request List */
    .list-card {
      border: 1px solid var(--border-color);
      border-radius: 16px;
      background: var(--card-bg-color);
      box-shadow: var(--shadow-medium);
      overflow: hidden;
      backdrop-filter: blur(10px);
    }
    
    .request-row {
      cursor: pointer;
      border-bottom: 1px solid rgba(230, 230, 230, 0.5);
      padding: 1.75rem 2rem;
      transition: all 0.3s ease;
      position: relative;
      background: linear-gradient(135deg, transparent 0%, rgba(255, 255, 255, 0.1) 100%);
    }
    
    .request-row:last-child {
      border-bottom: none;
    }
    
    .request-row:hover {
      background: linear-gradient(135deg, rgba(188, 158, 66, 0.05) 0%, rgba(188, 158, 66, 0.02) 100%);
      transform: translateX(4px);
      box-shadow: inset 4px 0 0 var(--secondary-color);
    }
    
    .request-row.unread {
      border-left: 4px solid var(--secondary-color);
      background: linear-gradient(135deg, rgba(188, 158, 66, 0.08) 0%, rgba(188, 158, 66, 0.02) 100%);
    }
    
    .request-content {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1.5rem;
    }
    
    .request-info {
      flex: 1;
      min-width: 0;
    }
    
    .client-name {
      font-weight: 700;
      font-size: 1.1rem;
      color: var(--primary-color);
      margin-bottom: 0.25rem;
    }
    
    .client-email {
      color: var(--text-muted);
      font-size: 0.9rem;
      margin-bottom: 0.75rem;
    }
    
    .request-meta {
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex-wrap: wrap;
      margin-bottom: 0.5rem;
    }
    
    .meta-item {
      display: flex;
      align-items: center;
      gap: 0.4rem;
      color: var(--text-muted);
      font-size: 0.85rem;
      font-weight: 500;
    }
    
    .property-address {
      display: flex;
      align-items: center;
      gap: 0.4rem;
      color: var(--text-muted);
      font-size: 0.85rem;
      font-weight: 500;
    }
    
    /* Status Badges */
    .status-badge {
      font-size: 0.75rem;
      font-weight: 700;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      border: 2px solid;
      transition: all 0.3s ease;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .status-pending {
      background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 193, 7, 0.1));
      color: #856404;
      border-color: rgba(255, 193, 7, 0.3);
    }
    
    .status-confirmed {
      background: linear-gradient(135deg, rgba(25, 135, 84, 0.2), rgba(25, 135, 84, 0.1));
      color: var(--success-color);
      border-color: rgba(25, 135, 84, 0.3);
    }
    
    .status-completed {
      background: linear-gradient(135deg, rgba(0, 128, 0, 0.2), rgba(0, 128, 0, 0.1));
      color: #006400;
      border-color: rgba(0, 128, 0, 0.3);
    }
    
    .status-cancelled {
      background: linear-gradient(135deg, rgba(220, 53, 69, 0.2), rgba(220, 53, 69, 0.1));
      color: var(--danger-color);
      border-color: rgba(220, 53, 69, 0.3);
    }
    
    .status-rejected {
      background: linear-gradient(135deg, rgba(220, 53, 69, 0.2), rgba(220, 53, 69, 0.1));
      color: var(--danger-color);
      border-color: rgba(220, 53, 69, 0.3);
    }
    
    .status-badge:hover {
      transform: scale(1.05);
    }

    /* Tour Type Badges */
    .type-badge {
      font-size: 0.7rem;
      font-weight: 800;
      padding: 0.25rem 0.6rem;
      border-radius: 999px;
      letter-spacing: 0.4px;
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      border: 1px solid;
      white-space: nowrap;
    }
    .type-public {
      background: linear-gradient(135deg, rgba(13, 202, 240, 0.18), rgba(13, 202, 240, 0.08));
      color: #0b7285;
      border-color: rgba(13, 202, 240, 0.35);
    }
    .type-private {
      background: linear-gradient(135deg, rgba(108, 117, 125, 0.18), rgba(108, 117, 125, 0.08));
      color: #495057;
      border-color: rgba(108, 117, 125, 0.35);
    }
    
    /* Unread Indicator */
    .unread-dot {
      width: 12px;
      height: 12px;
      background: linear-gradient(135deg, var(--secondary-color), #d4b555);
      border-radius: 50%;
      display: inline-block;
      box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.2);
      animation: pulse 2s infinite;
      position: relative;
    }
    
    .unread-dot::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 6px;
      height: 6px;
      background: var(--card-bg-color);
      border-radius: 50%;
      transform: translate(-50%, -50%);
    }
    
    @keyframes pulse {
      0% { box-shadow: 0 0 0 0 rgba(188, 158, 66, 0.7); }
      70% { box-shadow: 0 0 0 6px rgba(188, 158, 66, 0); }
      100% { box-shadow: 0 0 0 0 rgba(188, 158, 66, 0); }
    }
    
    /* Details Modal */
    .modal-content {
      border: none;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }
    
    .modal-header {
      background: linear-gradient(135deg, var(--primary-color) 0%, #2a1f0f 100%);
      color: #fff;
      border: none;
      padding: 1.5rem 2rem;
    }
    
    .modal-title {
      font-weight: 700;
      font-size: 1.25rem;
    }
    
    .modal-header .btn-close {
      filter: invert(1) brightness(200%);
      opacity: 0.8;
      transition: all 0.3s ease;
    }
    
    .modal-header .btn-close:hover {
      opacity: 1;
      transform: scale(1.1);
    }
    
    .modal-body {
      padding: 2rem;
      background: linear-gradient(135deg, var(--card-bg-color) 0%, #fafafa 100%);
    }
    
    .modal-footer {
      background: var(--card-bg-color);
      border: none;
      padding: 1.5rem 2rem;
    }
    
    /* Status Tabs Enhancement */
    .nav-pills {
      background: var(--card-bg-color);
      padding: 1rem;
      border-radius: 16px;
      box-shadow: var(--shadow-light);
      margin-bottom: 2rem;
    }
    
    .nav-pills .nav-link {
      border-radius: 12px;
      font-weight: 600;
      padding: 0.75rem 1.25rem;
      transition: all 0.3s ease;
      color: var(--text-muted);
      border: 2px solid transparent;
    }
    
    .nav-pills .nav-link:hover {
      background: rgba(188, 158, 66, 0.1);
      color: var(--primary-color);
      transform: translateY(-2px);
    }
    
    .nav-pills .nav-link.active {
      background: linear-gradient(135deg, var(--secondary-color), #d4b555);
      color: var(--primary-color);
      border-color: rgba(188, 158, 66, 0.3);
      box-shadow: 0 4px 12px rgba(188, 158, 66, 0.2);
    }
    
    .nav-pills .badge {
      background: rgba(255, 255, 255, 0.9);
      color: var(--primary-color);
      font-weight: 700;
    }
    
    .nav-pills .nav-link.active .badge {
      background: rgba(22, 18, 9, 0.8);
      color: var(--card-bg-color);
    }
    
    /* Empty State */
    .empty-state {
      padding: 4rem 2rem;
      text-align: center;
      color: var(--text-muted);
      background: linear-gradient(135deg, rgba(188, 158, 66, 0.03) 0%, rgba(188, 158, 66, 0.01) 100%);
    }
    
    .empty-state i {
      font-size: 4rem;
      margin-bottom: 1.5rem;
      color: rgba(188, 158, 66, 0.3);
      animation: float 3s ease-in-out infinite;
    }
    
    .empty-state h5 {
      color: var(--primary-color);
      font-weight: 700;
      margin-bottom: 0.5rem;
    }
    
    @keyframes float {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-10px); }
    }
    
    /* Interactive Elements */
    .interactive-card {
      transition: all 0.3s ease;
      cursor: pointer;
    }
    
    .interactive-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-heavy);
    }
    
    /* Loading States */
    .loading-pulse {
      animation: pulse-loading 1.5s ease-in-out infinite;
    }
    
    @keyframes pulse-loading {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }
    
    /* Quick Stats */
    .stats-overview {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }
    
    .stat-card {
      background: linear-gradient(135deg, var(--card-bg-color) 0%, #fafafa 100%);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 1.5rem;
      text-align: center;
      box-shadow: var(--shadow-light);
      transition: all 0.3s ease;
    }
    
    .stat-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-medium);
    }
    
    .stat-number {
      font-size: 2rem;
      font-weight: 800;
      color: var(--secondary-color);
      display: block;
    }
    
    .stat-label {
      color: var(--text-muted);
      font-size: 0.875rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    /* Calendar Sidebar (Right-side slide-in) */
    .calendar-sidebar {
      position: fixed;
      top: 0;
      right: 0;
      width: 100%;
      height: 100%;
      z-index: 9999;
      pointer-events: none;
    }
    
    .calendar-sidebar.active {
      pointer-events: all;
    }
    
    .calendar-sidebar-overlay {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      opacity: 0;
      transition: opacity 0.3s ease;
      pointer-events: none;
    }
    
    .calendar-sidebar.active .calendar-sidebar-overlay {
      opacity: 1;
      pointer-events: all;
    }
    
    .calendar-sidebar-content {
      position: absolute;
      top: 0;
      right: 0;
      width: 450px;
      max-width: 90vw;
      height: 100%;
      background: linear-gradient(135deg, var(--card-bg-color) 0%, #fafafa 100%);
      box-shadow: -4px 0 30px rgba(0, 0, 0, 0.2);
      transform: translateX(100%);
      transition: transform 0.3s ease;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    
    .calendar-sidebar.active .calendar-sidebar-content {
      transform: translateX(0);
    }
    
    .calendar-header {
      background: linear-gradient(135deg, #bc9e42 0%, #f7e9b0 100%);
      color: #3a2c06;
      padding: 1.5rem 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 3px solid #bc9e42;
      box-shadow: 0 4px 10px rgba(188, 158, 66, 0.18);
    }
    
    .calendar-header h4 {
      font-weight: 700;
      font-size: 1.25rem;
      display: flex;
      align-items: center;
      text-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .debug-toggle-container {
      padding: 1rem 2rem;
      background: linear-gradient(to right, #fff3cd 0%, #fffbf0 100%);
      border-bottom: 2px solid #ffc107;
    }
    
    .debug-toggle-container .form-check-label {
      color: #856404;
      font-weight: 500;
      cursor: pointer;
      font-size: 0.875rem;
    }
    
    .debug-toggle-container .form-check-input {
      cursor: pointer;
      width: 2.5rem;
      height: 1.25rem;
    }
    
    .debug-toggle-container .form-check-input:checked {
      background-color: #ffc107;
      border-color: #ffc107;
    }
    
    .btn-close-sidebar {

      border: 1px solid rgba(255, 255, 255, 0.2);
      color: black;
      width: 36px;
      height: 36px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s ease;
      backdrop-filter: blur(10px);
    }
    
    .btn-close-sidebar:hover {
      background: rgba(255, 255, 255, 0.25);
      transform: scale(1.1) rotate(90deg);
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    
    .calendar-body {
      flex: 1;
      overflow-y: auto;
      padding: 1.5rem;
    }
    
    /* Calendar Widget */
    .calendar-widget {
      background: var(--card-bg-color);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: var(--shadow-light);
    }
    
    .calendar-controls {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1rem;
    }
    
    .calendar-month-year {
      font-weight: 700;
      color: var(--primary-color);
      font-size: 1.1rem;
    }
    
    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 0.25rem;
      margin-bottom: 1rem;
    }
    
    .calendar-day-header {
      text-align: center;
      font-weight: 700;
      font-size: 0.75rem;
      color: var(--text-muted);
      padding: 0.5rem 0;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .calendar-day {
      aspect-ratio: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      font-size: 0.875rem;
      font-weight: 600;
      cursor: pointer;
      transition: background-color 0.12s ease, border-color 0.12s ease;
      position: relative;
      background: var(--card-bg-color);
      border: 1px solid transparent;
    }
    
    .calendar-day:hover:not(.disabled) {
      background: rgba(188, 158, 66, 0.06);
      border-color: rgba(188, 158, 66, 0.3);
    }
    
    .calendar-day.disabled {
      color: #ccc;
      cursor: not-allowed;
      opacity: 0.5;
    }
    
    .calendar-day.today {
      background: rgba(188, 158, 66, 0.2);
      border: 2px solid var(--secondary-color);
      font-weight: 800;
    }
    
    .calendar-day.selected {
      background: linear-gradient(135deg, var(--secondary-color), #d4b555);
      color: var(--primary-color);
      font-weight: 800;
    }
    
    .calendar-day.has-tours::after {
      content: '';
      position: absolute;
      bottom: 6px;
      left: 50%;
      transform: translateX(-50%);
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--secondary-color);
      box-shadow: none;
    }
    
    .calendar-day.has-pending::after {
      background: var(--warning-color);
    }
    
    .calendar-day.has-confirmed::after {
      background: var(--success-color);
    }
    
    /* Debug-mode markers (no animations) */
    .calendar-day.has-cancelled::after {
      background: #6c757d; /* muted gray */
      box-shadow: none;
    }

    .calendar-day.has-rejected::after {
      background: var(--danger-color); /* red */
      box-shadow: none;
    }

    .calendar-day.has-completed::after {
      background: var(--info-color); /* cyan */
      box-shadow: none;
    }
    
    /* Completed state removed - only pending and confirmed will be shown */
    
    .calendar-legend {
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
      padding-top: 1rem;
      border-top: 2px solid #e9ecef;
    }
    
    .legend-item {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.8rem;
      color: #495057;
      font-weight: 600;
      padding: 0.25rem 0.75rem;
      background: rgba(102, 126, 234, 0.05);
      border-radius: 20px;
      transition: all 0.2s ease;
    }
    
    .legend-item:hover {
      background: rgba(102, 126, 234, 0.1);
      transform: translateY(-1px);
    }
    
    .legend-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      display: inline-block;
      box-shadow: 0 0 4px rgba(0,0,0,0.2);
    }
    
    /* Scheduled Tours Section */
    .scheduled-tours-section {
      background: var(--card-bg-color);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: var(--shadow-light);
    }
    
    .section-header {
      padding: 1rem 1.5rem;
      background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.05));
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    
    .section-header h6 {
      font-weight: 700;
      color: var(--primary-color);
    }
    
    .scheduled-tours-list {
      max-height: 400px;
      overflow-y: auto;
    }
    
    .tour-item {
      padding: 1rem 1.5rem;
      border-bottom: 1px solid rgba(230, 230, 230, 0.5);
      border-left: 3px solid transparent;
      transition: background-color 0.12s ease, border-left-color 0.12s ease;
      cursor: pointer;
      position: relative;
      transform: none;
    }

    .tour-item:last-child {
      border-bottom: none;
    }

    .tour-item:hover {
      background: rgba(102, 126, 234, 0.02);
      border-left-color: #667eea;
      box-shadow: none;
    }
    
    /* Status-based left border colors */
    .tour-item.status-pending {
      border-left-color: var(--warning-color);
    }
    
    .tour-item.status-pending:hover {
      border-left-color: var(--warning-color);
    }
    
    .tour-item.status-confirmed {
      border-left-color: var(--success-color);
    }
    
    .tour-item.status-confirmed:hover {
      border-left-color: var(--success-color);
    }
    
    .tour-item.status-cancelled {
      border-left-color: #6c757d;
      opacity: 0.7;
    }
    
    .tour-item.status-rejected {
      border-left-color: var(--danger-color);
      opacity: 0.7;
    }
    
    .tour-item.status-completed {
      border-left-color: var(--info-color);
      opacity: 0.8;
    }
    
    .tour-item-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.5rem;
    }
    
    .tour-client-name {
      font-weight: 700;
      color: var(--primary-color);
      font-size: 0.95rem;
    }
    
    .tour-time {
      display: flex;
      align-items: center;
      gap: 0.3rem;
      color: var(--text-muted);
      font-size: 0.85rem;
      font-weight: 600;
    }
    
    .tour-property {
      color: var(--text-muted);
      font-size: 0.8rem;
      margin-bottom: 0.3rem;
    }
    
    .tour-conflict-warning {
      background: linear-gradient(to right, rgba(255, 193, 7, 0.15) 0%, rgba(255, 193, 7, 0.05) 100%);
      border: 1px solid rgba(255, 193, 7, 0.4);
      border-left: 4px solid var(--warning-color);
      border-radius: 8px;
      box-shadow: 0 2px 6px rgba(255, 193, 7, 0.2);
      padding: 0.6rem 0.85rem;
      margin-top: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.6rem;
  font-size: 0.8rem;
  color: #856404;
  font-weight: 600;
    }
    
    .tour-conflict-warning i {
      color: var(--warning-color);
      font-size: 1rem;
    }
    
    /* removed warningPulse keyframes to minimize motion */
    
    .tour-item-empty {
      padding: 3rem 1.5rem;
      text-align: center;
      color: var(--text-muted);
    }
    
    .tour-item-empty i {
      font-size: 4rem;
      margin-bottom: 1.5rem;
      opacity: 0.15;
      color: #667eea;
    }
    
    .tour-item-empty p {
      font-size: 0.9rem;
      font-weight: 500;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      
      .btn-back {
        width: 100%;
      }
      
      .calendar-sidebar-content {
        width: 100%;
        max-width: 100%;
      }
    }
  </style>
</head>
<body>
<?php include 'logout_agent_modal.php'; ?>

<?php 
// Set this file as active in navbar
$active_page = 'agent_tour_requests.php';
include 'agent_navbar.php'; 
?>

<div class="content">
  <div class="page-header">
    <h1 class="page-title">Requested Tours</h1>
    <div class="d-flex gap-2">
      <button class="btn btn-brand" id="openCalendarBtn">
        <i class="fas fa-calendar-alt me-2"></i>View Calendar
      </button>
      <a href="agent_property.php" class="btn btn-outline-secondary btn-back">
        <i class="fas fa-arrow-left me-2"></i>Back to Properties
      </a>
    </div>
  </div>

    <!-- Quick Stats Overview -->
    <div class="stats-overview">
      <div class="stat-card">
        <span class="stat-number"><?php echo $counts['All']; ?></span>
        <span class="stat-label">Total Requests</span>
      </div>
      <div class="stat-card">
        <span class="stat-number"><?php echo $counts['Pending']; ?></span>
        <span class="stat-label">Awaiting Response</span>
      </div>
      <div class="stat-card">
        <span class="stat-number"><?php echo $counts['Confirmed']; ?></span>
        <span class="stat-label">Scheduled Tours</span>
      </div>
      <div class="stat-card">
        <span class="stat-number"><?php echo $counts['Completed']; ?></span>
        <span class="stat-label">Completed</span>
      </div>
    </div>

    <div class="filter-bar">
      <h5 class="filter-title">
        <i class="fas fa-sliders-h"></i>
        Filter & Search Requests
      </h5>
      <form method="get" class="row g-4 align-items-end">
        <div class="col-sm-6 col-md-4">
          <label class="form-label fw-semibold text-muted">
            <i class="fas fa-building me-1"></i>
            Property Filter
          </label>
          <select name="property_id" class="form-select">
            <option value="0">🏢 All Properties</option>
            <?php foreach ($properties as $prop): ?>
              <option value="<?php echo (int)$prop['property_ID']; ?>" <?php echo $filter_property_id==(int)$prop['property_ID']?'selected':''; ?>>
                📍 <?php echo htmlspecialchars($prop['title']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 col-md-3">
          <button class="btn btn-brand w-100" type="submit">
            <i class="fas fa-search me-2"></i>Apply Filters
          </button>
        </div>
      </form>
    </div>

    <!-- Status Tabs -->
    <ul class="nav nav-pills mb-3">
      <?php foreach (['All','Pending','Confirmed','Completed','Cancelled','Rejected'] as $tab): 
            $active = $active_status === $tab ? 'active' : ''; 
            $count = $counts[$tab];
            $q = http_build_query(array_filter([
                'property_id' => $filter_property_id ?: null,
                'status' => $tab,
            ]));
      ?>
        <li class="nav-item me-2 mb-2">
          <a class="nav-link <?php echo $active; ?>" href="?<?php echo $q; ?>">
            <?php echo $tab; ?>
            <span class="badge bg-secondary ms-1"><?php echo (int)$count; ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="list-card">
      <?php if (empty($display_requests)): ?>
        <div class="empty-state">
          <i class="fas fa-calendar-plus"></i>
          <h5>No <?php echo $active_status === 'All' ? '' : strtolower($active_status); ?> tour requests found</h5>
          <p class="mb-0">
            <?php if ($active_status === 'Pending'): ?>
              You're all caught up! No pending requests require your attention.
            <?php elseif ($active_status === 'Confirmed'): ?>
              No confirmed tours scheduled at the moment.
            <?php elseif ($active_status === 'Completed'): ?>
              No completed tours to display.
            <?php else: ?>
              When clients request property tours, they'll appear here for your review and response.
            <?php endif; ?>
          </p>
        </div>
      <?php else: ?>
        <?php foreach ($display_requests as $req): 
          $isUnread = (int)$req['is_read_by_agent'] === 0;
          $status = $req['request_status'];
          $urgentRequest = (strtotime($req['tour_date']) - time()) < (24 * 60 * 60); // Within 24 hours
        ?>
          <div class="request-row <?php echo $isUnread ? 'unread' : ''; ?>" data-tour-id="<?php echo (int)$req['tour_id']; ?>">
            <div class="request-content">
              <div class="d-flex align-items-start gap-3">
                <div class="pt-1">
                  <?php if ($isUnread): ?>
                    <span class="unread-dot" title="New Request"></span>
                  <?php else: ?>
                    <div style="width: 18px;"></div>
                  <?php endif; ?>
                </div>
                <div class="request-info">
                  <div class="client-name">
                    <?php echo htmlspecialchars($req['user_name']); ?>
                    <?php if ($urgentRequest && $status === 'Pending'): ?>
                      <i class="fas fa-exclamation-triangle text-warning ms-2" title="Urgent: Tour scheduled within 24 hours"></i>
                    <?php endif; ?>
                  </div>
                  <div class="client-email">
                    <i class="fas fa-envelope me-1"></i>
                    <?php echo htmlspecialchars($req['user_email']); ?>
                  </div>
                  <div class="request-meta">
                    <div class="meta-item">
                      <i class="far fa-calendar text-primary"></i>
                      <?php echo date('M j, Y', strtotime($req['tour_date'])); ?>
                    </div>
                    <div class="meta-item">
                      <i class="far fa-clock text-info"></i>
                      <?php echo date('g:i A', strtotime($req['tour_time'])); ?>
                    </div>
                    <div class="meta-item">
                      <?php if (($req['tour_type'] ?? 'private') === 'public'): ?>
                        <span class="type-badge type-public" title="Public (Group) tour"><i class="fas fa-users"></i> Public</span>
                      <?php else: ?>
                        <span class="type-badge type-private" title="Private tour"><i class="fas fa-user"></i> Private</span>
                      <?php endif; ?>
                    </div>
                    <div class="meta-item">
                      <i class="fas fa-clock text-muted"></i>
                      <?php echo date('M j, g:i A', strtotime($req['requested_at'])); ?>
                    </div>
                  </div>
                  <div class="property-address">
                    <i class="fas fa-location-dot text-secondary"></i>
                    <?php echo htmlspecialchars($req['StreetAddress'] . ', ' . $req['City']); ?>
                  </div>
                </div>
              </div>
              <div class="d-flex flex-column align-items-end gap-2">
                <?php 
                  if ($status === 'Completed') {
                    $cls = 'status-completed';
                    $label = 'Completed';
                    $icon = '<i class="fas fa-clipboard-check"></i>';
                  } elseif ($status === 'Confirmed') {
                    $cls = 'status-confirmed';
                    $label = 'Confirmed';
                    $icon = '<i class="fas fa-check"></i>';
                  } elseif ($status === 'Cancelled') {
                    $cls = 'status-cancelled';
                    $label = 'Cancelled';
                    $icon = '<i class="fas fa-ban"></i>';
                  } elseif ($status === 'Rejected') {
                    $cls = 'status-rejected';
                    $label = 'Rejected';
                    $icon = '<i class="fas fa-times"></i>';
                  } else {
                    $cls = 'status-pending';
                    $label = 'Pending';
                    $icon = '<i class="fas fa-clock"></i>';
                  }
                ?>
                <span class="status-badge <?php echo $cls; ?>"><?php echo $icon . ' ' . $label; ?></span>
                <?php if ($status === 'Pending'): ?>
                  <small class="text-muted">
                    <i class="fas fa-mouse-pointer me-1"></i>Click to respond
                  </small>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal -->
  <div class="modal fade" id="tourDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">Tour Request Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="tourDetailsBody" class="position-relative">
            <div class="d-flex justify-content-center py-4">
              <div class="spinner-border spinner-border-sm text-secondary" role="status">
                <span class="visually-hidden">Loading...</span>
              </div>
            </div>
          </div>
          <div id="tourDetailsAlert" class="alert d-none mt-3" role="alert"></div>
        </div>
        <div class="modal-footer border-top">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Close
          </button>
          <button type="button" class="btn btn-danger d-none" id="rejectTourBtn">
            <i class="fas fa-ban me-2"></i>Reject
          </button>
          <button type="button" class="btn btn-outline-danger d-none" id="cancelTourBtn">
            <i class="fas fa-xmark me-2"></i>Cancel Tour
          </button>
          <button type="button" class="btn btn-success d-none" id="completeTourBtn">
            <i class="fas fa-clipboard-check me-2"></i>Mark Completed
          </button>
          <button type="button" class="btn btn-brand" id="acceptTourBtn" data-tour-id="">
            <i class="fas fa-check me-2"></i>Confirm Schedule
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Reason Modal -->
  <div class="modal fade" id="reasonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="reasonModalTitle">Add Reason</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Please provide a brief reason</label>
            <textarea class="form-control" id="reasonText" rows="4" placeholder="Type your reason..." required></textarea>
            <div class="form-text text-muted">This will be sent to the client via email.</div>
          </div>
          <div id="reasonAlert" class="alert d-none" role="alert"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-danger d-none" id="submitRejectBtn">
            <i class="fas fa-ban me-2"></i>Reject Request
          </button>
          <button type="button" class="btn btn-outline-danger d-none" id="submitCancelBtn">
            <i class="fas fa-xmark me-2"></i>Cancel Tour
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Calendar Sidebar Modal (Right-side slide-in) -->
  <div class="calendar-sidebar" id="calendarSidebar">
    <div class="calendar-sidebar-overlay" id="calendarOverlay"></div>
    <div class="calendar-sidebar-content">
      <div class="calendar-header">
        <h4 class="mb-0">
          <i class="fas fa-calendar-alt me-2"></i>Tour Schedule Calendar
        </h4>
        <button class="btn-close-sidebar" id="closeCalendarBtn">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <!-- Debug Toggle -->
      <div class="debug-toggle-container">
        <div class="form-check form-switch">
          <input class="form-check-input me-2" type="checkbox" id="showAllStatusesToggle" onchange="toggleDebugMode()">
          <label class="form-check-label" for="showAllStatusesToggle">
            <small> Show All Status</small>
          </label>
        </div>
      </div>
      
      <div class="calendar-body">
        <!-- Calendar Widget -->
        <div class="calendar-widget">
          <div class="calendar-controls">
            <button class="btn btn-sm btn-outline-secondary" id="prevMonthBtn">
              <i class="fas fa-chevron-left"></i>
            </button>
            <h5 class="calendar-month-year mb-0" id="calendarMonthYear">January 2025</h5>
            <button class="btn btn-sm btn-outline-secondary" id="nextMonthBtn">
              <i class="fas fa-chevron-right"></i>
            </button>
          </div>
          
          <div class="calendar-grid" id="calendarGrid">
            <!-- Calendar will be rendered here by JS -->
          </div>
          
          <div class="calendar-legend">
            <div class="legend-item">
              <span class="legend-dot" style="background: var(--warning-color);"></span>
              <span>Pending</span>
            </div>
            <div class="legend-item">
              <span class="legend-dot" style="background: var(--success-color);"></span>
              <span>Confirmed</span>
            </div>
            <div class="legend-item debug-only" style="display: none;">
              <span class="legend-dot" style="background: #6c757d;"></span>
              <span>Cancelled</span>
            </div>
            <div class="legend-item debug-only" style="display: none;">
              <span class="legend-dot" style="background: var(--danger-color);"></span>
              <span>Rejected</span>
            </div>
            <div class="legend-item debug-only" style="display: none;">
              <span class="legend-dot" style="background: var(--info-color);"></span>
              <span>Completed</span>
            </div>
          </div>
        </div>
        
        <!-- Scheduled Tours List -->
        <div class="scheduled-tours-section">
          <div class="section-header">
            <h6 class="mb-0" id="scheduledToursTitle">
              <i class="fas fa-list me-2"></i>All Scheduled Tours (<span id="tourCount">0</span>)
            </h6>
            <button class="btn btn-sm btn-outline-secondary" id="clearDateFilter" style="display:none;">
              <i class="fas fa-times me-1"></i>Clear Filter
            </button>
          </div>
          
          <div class="scheduled-tours-list" id="scheduledToursList">
            <!-- Tours will be rendered here by JS -->
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ===== CALENDAR FUNCTIONALITY =====
    const calendarData = {
      currentDate: new Date(),
      selectedDate: null,
      tours: <?php echo json_encode($tour_requests); ?>,
      toursByDate: {}
    };
    
    // Normalize date to YYYY-MM-DD (handles values like 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS')
    function normalizeDateKey(value) {
      if (!value) return '';
      return String(value).slice(0, 10);
    }
    
    // Debug mode state
    let debugMode = false;
    
    // Toggle debug mode
    function toggleDebugMode() {
      debugMode = document.getElementById('showAllStatusesToggle').checked;
      
      // Show/hide debug legend items
      document.querySelectorAll('.legend-item.debug-only').forEach(item => {
        item.style.display = debugMode ? 'flex' : 'none';
      });
      
      // Reinitialize and re-render calendar
      initializeToursByDate();
      renderCalendar();
      renderScheduledTours();
      
      // Console log for debugging
      console.log('Debug mode:', debugMode ? 'ON' : 'OFF');
      console.log('Calendar data:', calendarData.toursByDate);
    }
    
    // Initialize tours by date
    function initializeToursByDate() {
      calendarData.toursByDate = {};
      // Include all tours in debug mode, otherwise only Pending and Confirmed
      calendarData.tours.forEach(tour => {
        const status = (tour.request_status || '').toString();
        // Skip rejected/cancelled/completed unless debug mode is on
        if (!debugMode && status !== 'Pending' && status !== 'Confirmed') return;
        
        const dateKey = normalizeDateKey(tour.tour_date);
        if (!dateKey) return;
        if (!calendarData.toursByDate[dateKey]) {
          calendarData.toursByDate[dateKey] = [];
        }
        calendarData.toursByDate[dateKey].push(tour);
      });
    }
    
    // Open/Close Calendar Sidebar
    const openCalBtn = document.getElementById('openCalendarBtn');
    if (openCalBtn) openCalBtn.addEventListener('click', () => {
      document.getElementById('calendarSidebar').classList.add('active');
      initializeToursByDate();
      renderCalendar();
      renderScheduledTours();
    });
    
    const closeCalBtn = document.getElementById('closeCalendarBtn');
    if (closeCalBtn) closeCalBtn.addEventListener('click', () => {
      document.getElementById('calendarSidebar').classList.remove('active');
    });
    
    const calOverlay = document.getElementById('calendarOverlay');
    if (calOverlay) calOverlay.addEventListener('click', () => {
      document.getElementById('calendarSidebar').classList.remove('active');
    });
    
    // Calendar Navigation
    document.getElementById('prevMonthBtn').addEventListener('click', () => {
      calendarData.currentDate.setMonth(calendarData.currentDate.getMonth() - 1);
      renderCalendar();
    });
    
    document.getElementById('nextMonthBtn').addEventListener('click', () => {
      calendarData.currentDate.setMonth(calendarData.currentDate.getMonth() + 1);
      renderCalendar();
    });
    
    // Render Calendar
    function renderCalendar() {
      const year = calendarData.currentDate.getFullYear();
      const month = calendarData.currentDate.getMonth();
      const firstDay = new Date(year, month, 1);
      const lastDay = new Date(year, month + 1, 0);
      const prevLastDay = new Date(year, month, 0);
      const firstDayIndex = firstDay.getDay();
      const lastDayIndex = lastDay.getDay();
      const nextDays = 7 - lastDayIndex - 1;
      
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                          'July', 'August', 'September', 'October', 'November', 'December'];
      
      document.getElementById('calendarMonthYear').textContent = `${monthNames[month]} ${year}`;
      
      const grid = document.getElementById('calendarGrid');
      grid.innerHTML = '';
      
      // Day headers
      const dayHeaders = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
      dayHeaders.forEach(day => {
        const header = document.createElement('div');
        header.className = 'calendar-day-header';
        header.textContent = day;
        grid.appendChild(header);
      });
      
      // Previous month days
      for (let i = firstDayIndex; i > 0; i--) {
        const day = document.createElement('div');
        day.className = 'calendar-day disabled';
        day.textContent = prevLastDay.getDate() - i + 1;
        grid.appendChild(day);
      }
      
      // Current month days
      const today = new Date();
      for (let i = 1; i <= lastDay.getDate(); i++) {
        const day = document.createElement('div');
        day.className = 'calendar-day';
        day.textContent = i;
        
  const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
        const isToday = i === today.getDate() && month === today.getMonth() && year === today.getFullYear();
        const isSelected = calendarData.selectedDate === dateKey;
        
        if (isToday) day.classList.add('today');
        if (isSelected) day.classList.add('selected');
        
        // Check if this date has tours
        if (calendarData.toursByDate[dateKey]) {
          const tours = calendarData.toursByDate[dateKey];
          day.classList.add('has-tours');
          
          // Determine status classes (primary markers)
          const hasConfirmed = tours.some(t => t.request_status === 'Confirmed');
          const hasPending = tours.some(t => t.request_status === 'Pending');
          
          if (hasConfirmed) day.classList.add('has-confirmed');
          else if (hasPending) day.classList.add('has-pending');
          
          // Debug mode: Add additional status markers
          if (debugMode) {
            const hasCancelled = tours.some(t => t.request_status === 'Cancelled');
            const hasRejected = tours.some(t => t.request_status === 'Rejected');
            const hasCompleted = tours.some(t => t.request_status === 'Completed');
            
            if (hasCancelled) day.classList.add('has-cancelled');
            if (hasRejected) day.classList.add('has-rejected');
            if (hasCompleted) day.classList.add('has-completed');
          }
        }
        
        day.addEventListener('click', () => selectDate(dateKey));
        grid.appendChild(day);
      }
      
      // Next month days
      for (let i = 1; i <= nextDays; i++) {
        const day = document.createElement('div');
        day.className = 'calendar-day disabled';
        day.textContent = i;
        grid.appendChild(day);
      }
    }
    
    // Select Date
    function selectDate(dateKey) {
      if (calendarData.selectedDate === dateKey) {
        // Deselect if clicking same date
        calendarData.selectedDate = null;
        document.getElementById('clearDateFilter').style.display = 'none';
      } else {
        calendarData.selectedDate = dateKey;
        document.getElementById('clearDateFilter').style.display = 'inline-block';
      }
      renderCalendar();
      renderScheduledTours();
    }
    
    // Clear Date Filter
    document.getElementById('clearDateFilter').addEventListener('click', () => {
      calendarData.selectedDate = null;
      document.getElementById('clearDateFilter').style.display = 'none';
      renderCalendar();
      renderScheduledTours();
    });
    
    // Render Scheduled Tours
    function renderScheduledTours() {
      const list = document.getElementById('scheduledToursList');
      const title = document.getElementById('scheduledToursTitle');
      const count = document.getElementById('tourCount');
      
      let toursToShow = calendarData.tours;
      let titleText = 'All Scheduled Tours';
      
      if (calendarData.selectedDate) {
        const key = normalizeDateKey(calendarData.selectedDate);
        toursToShow = calendarData.toursByDate[key] || [];
        const date = new Date(calendarData.selectedDate + 'T00:00:00');
        titleText = `Tours on ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
      }
      
      // Filter out completed, cancelled, and rejected (unless debug mode is on)
      if (!debugMode) {
        toursToShow = toursToShow.filter(t => ['Pending', 'Confirmed'].includes(t.request_status));
      }
      
      count.textContent = toursToShow.length;
      title.innerHTML = `<i class=\"fas fa-list me-2\"></i>${titleText} (<span id=\"tourCount\">${toursToShow.length}</span>)`;
      
      if (toursToShow.length === 0) {
        list.innerHTML = `
          <div class="tour-item-empty">
            <i class="fas fa-calendar-times"></i>
            <p class="mb-0">No tours scheduled for this ${calendarData.selectedDate ? 'date' : 'period'}</p>
          </div>
        `;
        return;
      }
      
      // Sort by time
      toursToShow.sort((a, b) => {
        const timeA = new Date(`2000-01-01T${a.tour_time}`);
        const timeB = new Date(`2000-01-01T${b.tour_time}`);
        return timeA - timeB;
      });
      
      // Group tours by exact time for conflict/grouping checks
      const timeGroups = new Map();
      toursToShow.forEach((tour, index) => {
        const key = `${tour.tour_date}_${tour.tour_time}`;
        if (!timeGroups.has(key)) timeGroups.set(key, []);
        timeGroups.get(key).push(index);
      });
      
      list.innerHTML = toursToShow.map((tour, index) => {
        const status = tour.request_status;
        let statusClass = 'status-pending';
        let statusIcon = 'fa-clock';
        
        if (status === 'Confirmed') {
          statusClass = 'status-confirmed';
          statusIcon = 'fa-check';
        } else if (status === 'Cancelled') {
          statusClass = 'status-cancelled';
          statusIcon = 'fa-ban';
        } else if (status === 'Rejected') {
          statusClass = 'status-rejected';
          statusIcon = 'fa-times';
        } else if (status === 'Completed') {
          statusClass = 'status-completed';
          statusIcon = 'fa-check-double';
        }
        
        const timeKey = `${tour.tour_date}_${tour.tour_time}`;
        // Determine if this tour has a real conflict (per public/private rules)
        let hasConflict = false;
        const idxs = timeGroups.get(timeKey) || [];
        if (idxs.length > 1) {
          for (const idx of idxs) {
            if (idx === index) continue;
            const other = toursToShow[idx];
            if ((tour.tour_type || 'private') === 'private') { hasConflict = true; break; }
            if ((tour.tour_type || 'private') === 'public') {
              if ((other.tour_type || 'private') === 'private' || String(other.property_id) !== String(tour.property_id)) { hasConflict = true; break; }
            }
          }
        }
        
        return `
          <div class="tour-item ${statusClass}" data-tour-id="${tour.tour_id}">
            <div class="tour-item-header">
              <div class="tour-client-name">${escapeHtml(tour.user_name)}</div>
              <span class="status-badge ${statusClass}">
                <i class="fas ${statusIcon}"></i>
              </span>
            </div>
            <div class="tour-time">
              <i class="far fa-clock"></i>
              ${formatTime(tour.tour_time)}
            </div>
            <div class="tour-property">
              <i class="fas fa-location-dot me-1"></i>
              ${escapeHtml(tour.StreetAddress + ', ' + tour.City)}
            </div>
            <div class="mt-1">
              ${tour.tour_type === 'public' 
                ? '<span class="type-badge type-public" title="Public (Group) tour"><i class="fas fa-users"></i> Public</span>'
                : '<span class="type-badge type-private" title="Private tour"><i class="fas fa-user"></i> Private</span>'}
            </div>
            ${hasConflict ? `
              <div class="tour-conflict-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Time conflict detected with another tour at this slot.</span>
              </div>
            ` : ''}
          </div>
        `;
      }).join('');
      
      // Add click handlers to tour items
      list.querySelectorAll('.tour-item').forEach(item => {
        item.addEventListener('click', () => {
          const tourId = item.getAttribute('data-tour-id');
          const row = document.querySelector(`.request-row[data-tour-id="${tourId}"]`);
          if (row) {
            document.getElementById('calendarSidebar').classList.remove('active');
            row.click();
          }
        });
      });
    }
    
    // Helper functions
    function formatTime(timeStr) {
      const date = new Date(`2000-01-01T${timeStr}`);
      return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    }
    
    function escapeHtml(text) {
      const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
      return String(text || '').replace(/[&<>"']/g, m => map[m]);
    }
    
    // ===== END CALENDAR FUNCTIONALITY =====
    
    // Click handler to load details and mark as read
    document.querySelectorAll('.request-row').forEach(row => {
      row.addEventListener('click', () => {
        const tourId = row.getAttribute('data-tour-id');
        fetch('tour_request_details.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'tour_id='+encodeURIComponent(tourId)+'&mark_read=1' })
          .then(r => r.json())
          .then(data => {
            const modalEl = document.getElementById('tourDetailsModal');
            const modal = new bootstrap.Modal(modalEl);
            const body = document.getElementById('tourDetailsBody');
            const btn = document.getElementById('acceptTourBtn');
            const alertBox = document.getElementById('tourDetailsAlert');
            const rejectBtn = document.getElementById('rejectTourBtn');
            const cancelBtn = document.getElementById('cancelTourBtn');

            alertBox.classList.add('d-none');
            btn.dataset.tourId = tourId;
            if (data.success && data.html) {
              body.innerHTML = data.html;
              // reset action buttons visibility each open
              rejectBtn.classList.add('d-none');
              cancelBtn.classList.add('d-none');
              // Update Accept/Reject/Cancel buttons availability based on status
              const completeTourBtn = document.getElementById('completeTourBtn');
              completeTourBtn.classList.add('d-none');
              completeTourBtn.dataset.tourId = tourId;
              
              if (data.status && data.status === 'Confirmed') {
                // Already confirmed: disable Confirm, show Cancel and Complete buttons
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-check-double me-2"></i>Already Confirmed';
                btn.classList.remove('btn-brand');
                btn.classList.add('btn-secondary');
                // Toggle action buttons
                cancelBtn.classList.remove('d-none');
                rejectBtn.classList.add('d-none');
                completeTourBtn.classList.remove('d-none');
                cancelBtn.dataset.tourId = tourId;
              } else if (data.status && data.status === 'Completed') {
                // Completed: disable all action buttons
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Tour Completed';
                btn.classList.remove('btn-brand');
                btn.classList.add('btn-success');
                cancelBtn.classList.add('d-none');
                rejectBtn.classList.add('d-none');
              } else if (data.status && (data.status === 'Cancelled' || data.status === 'Rejected')) {
                // Cancelled or Rejected: disable Accept, hide others
                btn.disabled = true;
                btn.innerHTML = data.status === 'Rejected' ? '<i class="fas fa-ban me-2"></i>Rejected' : '<i class="fas fa-ban me-2"></i>Cancelled';
                btn.classList.remove('btn-brand');
                btn.classList.add('btn-secondary');
                cancelBtn.classList.add('d-none');
                rejectBtn.classList.add('d-none');
              } else {
                // Pending: enable Accept, show Reject
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
                btn.classList.add('btn-brand');
                btn.classList.remove('btn-secondary');
                cancelBtn.classList.add('d-none');
                rejectBtn.classList.remove('d-none');
                rejectBtn.dataset.tourId = tourId;
              }
            } else {
              body.innerHTML = '<div class="text-danger">'+(data.message || 'Failed to load details')+'</div>';
              btn.disabled = true;
              // Hide action buttons on error
              const rejectBtn = document.getElementById('rejectTourBtn');
              const cancelBtn = document.getElementById('cancelTourBtn');
              if (rejectBtn) rejectBtn.classList.add('d-none');
              if (cancelBtn) cancelBtn.classList.add('d-none');
            }
            modal.show();

            // Mark the row as read in UI (remove unread dot)
            const dot = row.querySelector('.unread-dot');
            if (dot) dot.remove();
          })
          .catch(err => console.error(err));
      });
    });

    // Confirm button with conflict check
    document.getElementById('acceptTourBtn').addEventListener('click', function() {
      const tourId = this.dataset.tourId;
      if (!tourId) return;
      const alertBox = document.getElementById('tourDetailsAlert');
      const acceptBtn = this;
      
      // First check for conflicts
      acceptBtn.disabled = true;
      acceptBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking availability...';
      
      fetch('check_tour_conflict.php', { 
        method: 'POST', 
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
        body: 'tour_id='+encodeURIComponent(tourId) 
      })
      .then(r => r.json())
      .then(conflictData => {
        if (!conflictData.success) {
          alertBox.classList.remove('d-none', 'alert-success');
          alertBox.classList.add('alert-danger');
          alertBox.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>' + (conflictData.message || 'Failed to check conflicts.');
          acceptBtn.disabled = false;
          acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
          return;
        }
        
        // Block if exact time conflict
        if (conflictData.has_exact_conflict) {
          alertBox.classList.remove('d-none', 'alert-success');
          alertBox.classList.add('alert-danger');
          alertBox.innerHTML = `
            <div class="d-flex align-items-start gap-2">
              <i class="fas fa-ban mt-1"></i>
              <div>
                <strong>Cannot Confirm:</strong><br>
                ${conflictData.message}
              </div>
            </div>
          `;
          acceptBtn.disabled = false;
          acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
          return;
        }

        // If there are grouped public tours at the same property/time, show non-blocking notice
        if (conflictData.group_public_notice) {
          alertBox.classList.remove('d-none', 'alert-danger', 'alert-success');
          alertBox.classList.add('alert-warning');
          const msg = conflictData.group_public_message || 'Another public tour for this property/time is already confirmed. This request will be grouped.';
          alertBox.innerHTML = `
            <div class="d-flex align-items-start gap-2 mb-3">
              <i class="fas fa-users mt-1"></i>
              <div>
                <strong>Group Tour Notice:</strong><br>
                ${msg}
              </div>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-secondary" id="cancelConfirmBtn">Cancel</button>
              <button class="btn btn-sm btn-success" id="proceedConfirmBtn">Proceed Anyway</button>
            </div>
          `;
          document.getElementById('cancelConfirmBtn').addEventListener('click', () => {
            alertBox.classList.add('d-none');
            acceptBtn.disabled = false;
            acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
          });
          document.getElementById('proceedConfirmBtn').addEventListener('click', () => {
            confirmTour(tourId, acceptBtn, alertBox);
          });
          acceptBtn.disabled = false;
          acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
          return;
        }
        
        // Show warning if same day (but proceed)
        if (conflictData.has_same_day_conflict) {
          alertBox.classList.remove('d-none', 'alert-danger', 'alert-success');
          alertBox.classList.add('alert-warning');
          alertBox.innerHTML = `
            <div class="d-flex align-items-start gap-2 mb-3">
              <i class="fas fa-exclamation-triangle mt-1"></i>
              <div>
                <strong>Schedule Warning:</strong><br>
                ${conflictData.message}
              </div>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-secondary" id="cancelConfirmBtn">Cancel</button>
              <button class="btn btn-sm btn-success" id="proceedConfirmBtn">Proceed Anyway</button>
            </div>
          `;
          
          document.getElementById('cancelConfirmBtn').addEventListener('click', () => {
            alertBox.classList.add('d-none');
            acceptBtn.disabled = false;
            acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
          });
          
          document.getElementById('proceedConfirmBtn').addEventListener('click', () => {
            confirmTour(tourId, acceptBtn, alertBox);
          });
          
          acceptBtn.disabled = false;
          acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
          return;
        }
        
        // No conflicts, proceed directly
        confirmTour(tourId, acceptBtn, alertBox);
      })
      .catch(err => {
        console.error(err);
        alertBox.classList.remove('d-none');
        alertBox.classList.add('alert-danger');
        alertBox.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Error checking conflicts. Please try again.';
        acceptBtn.disabled = false;
        acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
      });
    });
    
    // Actually confirm the tour
    function confirmTour(tourId, acceptBtn, alertBox) {
      acceptBtn.disabled = true;
      acceptBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Confirming...';
      
      fetch('tour_request_accept.php', { 
        method: 'POST', 
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
        body: 'tour_id='+encodeURIComponent(tourId) 
      })
      .then(r => r.json())
      .then(data => {
        alertBox.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
        alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
        
        if (data.success) {
          alertBox.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + (data.message || 'Tour request confirmed successfully.');
          
          // Update badge in list
          const row = document.querySelector('.request-row[data-tour-id="'+tourId+'"]');
          if (row) {
            const badge = row.querySelector('.status-badge');
            if (badge) { 
              badge.className = 'status-badge status-confirmed'; 
              badge.innerHTML = '<i class="fas fa-check me-1"></i>Confirmed'; 
            }
          }
          
          // Update accept button
          acceptBtn.disabled = true;
          acceptBtn.innerHTML = '<i class="fas fa-check-double me-2"></i>Already Confirmed';
          acceptBtn.classList.remove('btn-brand');
          acceptBtn.classList.add('btn-secondary');
          
          // Refresh calendar data
          location.reload();
        } else {
          alertBox.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>' + (data.message || 'Failed to confirm tour request.');
          acceptBtn.disabled = false;
          acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
        }
      })
      .catch(err => {
        alertBox.classList.remove('d-none');
        alertBox.classList.add('alert-danger');
        alertBox.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Unexpected error. Please try again.';
        acceptBtn.disabled = false;
        acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
      });
    }

    // REJECT/CANCEL flows
    const reasonModalEl = document.getElementById('reasonModal');
    const reasonModal = new bootstrap.Modal(reasonModalEl);
    const reasonText = document.getElementById('reasonText');
    const reasonAlert = document.getElementById('reasonAlert');
    const submitRejectBtn = document.getElementById('submitRejectBtn');
    const submitCancelBtn = document.getElementById('submitCancelBtn');
    let reasonAction = null; // 'reject' or 'cancel'
    let reasonTourId = null;

    // Open reason modal for Reject
    document.getElementById('rejectTourBtn').addEventListener('click', function() {
      reasonAction = 'reject';
      reasonTourId = this.dataset.tourId;
      document.getElementById('reasonModalTitle').textContent = 'Reject Tour Request';
      submitRejectBtn.classList.remove('d-none');
      submitCancelBtn.classList.add('d-none');
      reasonText.value = '';
      reasonAlert.classList.add('d-none');
      reasonModal.show();
    });

    // Open reason modal for Cancel
    document.getElementById('cancelTourBtn').addEventListener('click', function() {
      reasonAction = 'cancel';
      reasonTourId = this.dataset.tourId;
      document.getElementById('reasonModalTitle').textContent = 'Cancel Accepted Tour';
      submitRejectBtn.classList.add('d-none');
      submitCancelBtn.classList.remove('d-none');
      reasonText.value = '';
      reasonAlert.classList.add('d-none');
      reasonModal.show();
    });

    function submitReason(endpoint) {
      const text = reasonText.value.trim();
      if (!text) {
        reasonAlert.classList.remove('d-none', 'alert-success');
        reasonAlert.classList.add('alert-danger');
        reasonAlert.textContent = 'Please provide a reason.';
        return;
      }
      const btn = reasonAction === 'reject' ? submitRejectBtn : submitCancelBtn;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
      fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'tour_id='+encodeURIComponent(reasonTourId)+'&reason='+encodeURIComponent(text) })
        .then(async r => {
          const ct = r.headers.get('content-type') || '';
          if (ct.includes('application/json')) {
            return r.json();
          } else {
            const t = await r.text();
            return { success: false, message: t && t.length < 500 ? t : 'Server returned an unexpected response.' };
          }
        })
        .then(data => {
          reasonAlert.classList.remove('d-none', 'alert-danger');
          reasonAlert.classList.add(data.success ? 'alert-success' : 'alert-danger');
          reasonAlert.textContent = data.message || (data.success ? 'Updated successfully.' : 'Failed to update.');
          if (data.success) {
            // Update main modal UI and list badge
            const listRow = document.querySelector('.request-row[data-tour-id="'+reasonTourId+'"]');
            if (listRow) {
              const badge = listRow.querySelector('.status-badge');
              if (badge) {
                badge.className = 'status-badge status-rejected';
                badge.innerHTML = '<i class="fas fa-ban me-1"></i>Rejected';
              }
            }
            // Also update main modal footer buttons
            document.getElementById('acceptTourBtn').disabled = true;
            document.getElementById('acceptTourBtn').innerHTML = '<i class="fas fa-ban me-2"></i>Rejected';
            document.getElementById('acceptTourBtn').classList.remove('btn-brand');
            document.getElementById('acceptTourBtn').classList.add('btn-secondary');
            document.getElementById('rejectTourBtn').classList.add('d-none');
            document.getElementById('cancelTourBtn').classList.add('d-none');

            // Refresh the details body to show updated status and reason
            fetch('tour_request_details.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: 'tour_id=' + encodeURIComponent(reasonTourId) + '&mark_read=0'
            })
            .then(r => r.json())
            .then(d => {
              if (d && d.success && d.html) {
                document.getElementById('tourDetailsBody').innerHTML = d.html;
              }
            })
            .catch(() => {/* no-op */});

            setTimeout(() => { reasonModal.hide(); }, 800);
          }
        })
        .catch((e) => {
          reasonAlert.classList.remove('d-none');
          reasonAlert.classList.add('alert-danger');
          reasonAlert.textContent = (e && e.message) ? e.message : 'Unexpected error. Please try again.';
        })
        .finally(() => {
          btn.disabled = false;
          btn.innerHTML = reasonAction === 'reject' ? '<i class="fas fa-ban me-2"></i>Reject Request' : '<i class="fas fa-xmark me-2"></i>Cancel Tour';
        });
    }

    submitRejectBtn.addEventListener('click', function() {
      submitReason('tour_request_reject.php');
    });
    submitCancelBtn.addEventListener('click', function() {
      submitReason('tour_request_cancel.php');
    });
    
    // Complete Tour button
    document.getElementById('completeTourBtn').addEventListener('click', function() {
      const tourId = this.dataset.tourId;
      if (!tourId) return;
      
      // Show confirmation message in the modal
      const alertBox = document.getElementById('tourDetailsAlert');
      alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');
      alertBox.classList.add('alert-warning');
      alertBox.innerHTML = `
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <i class="fas fa-question-circle me-2"></i>
            Are you sure you want to mark this tour as completed?
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-secondary" id="cancelCompleteTourBtn">No, Cancel</button>
            <button class="btn btn-sm btn-success" id="confirmCompleteTourBtn">Yes, Complete</button>
          </div>
        </div>
      `;
      
      // Add cancel button handler
      document.getElementById('cancelCompleteTourBtn').addEventListener('click', function() {
        alertBox.classList.add('d-none');
      });
      
      // Add confirm button handler
      document.getElementById('confirmCompleteTourBtn').addEventListener('click', function() {
        // Update UI
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
        document.getElementById('cancelCompleteTourBtn').disabled = true;
      
      fetch('tour_request_complete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tour_id=' + encodeURIComponent(tourId)
      })
      .then(r => r.json())
      .then(data => {
        // Clear confirmation message
        alertBox.classList.remove('d-none', 'alert-warning', 'alert-danger');
        
        if (data.success) {
          // Show success message
          alertBox.classList.add('alert-success');
          alertBox.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + (data.message || 'Tour marked as completed successfully.');
          
          // Update badge in list
          const row = document.querySelector('.request-row[data-tour-id="'+tourId+'"]');
          if (row) {
            const badge = row.querySelector('.status-badge');
              if (badge) {
                badge.className = 'status-badge status-completed';
                badge.innerHTML = '<i class="fas fa-clipboard-check me-1"></i>Completed';
              }
          }
          
          // Update UI buttons
          document.getElementById('acceptTourBtn').disabled = true;
          document.getElementById('acceptTourBtn').innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Tour Completed';
          document.getElementById('acceptTourBtn').classList.remove('btn-brand', 'btn-secondary');
          document.getElementById('acceptTourBtn').classList.add('btn-success');
          
          // Hide other action buttons
          document.getElementById('cancelTourBtn').classList.add('d-none');
          document.getElementById('completeTourBtn').classList.add('d-none');
          
          // Refresh the details to show completed info
          fetch('tour_request_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'tour_id=' + encodeURIComponent(tourId) + '&mark_read=0'
          })
          .then(r => r.json())
          .then(d => {
            if (d && d.success && d.html) {
              document.getElementById('tourDetailsBody').innerHTML = d.html;
            }
          })
          .catch(() => {/* no-op */});
        } else {
          // Show error message
          alertBox.classList.add('alert-danger');
          alertBox.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>' + (data.message || 'Failed to mark tour as completed.');
          
          // Re-enable the Complete button
          document.getElementById('completeTourBtn').disabled = false;
          document.getElementById('completeTourBtn').innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Mark Completed';
        }
      })
      .catch(err => {
        console.error(err);
        alertBox.classList.remove('alert-warning');
        alertBox.classList.add('alert-danger');
        alertBox.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Unexpected error. Please try again.';
        
        // Re-enable the Complete button
        document.getElementById('completeTourBtn').disabled = false;
        document.getElementById('completeTourBtn').innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Mark Completed';
        });
      });
    });
  </script>
</body>
</html>
