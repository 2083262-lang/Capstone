<?php
session_start();
include '../connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header('Location: ../login.php');
    exit();
}

$agent_account_id = $_SESSION['account_id'];
$agent_username = $_SESSION['username'];

// ===== AUTO-EXPIRE PENDING TOUR REQUESTS =====
// Use Philippine Time (Asia/Manila, UTC+8) for all date/time comparisons
date_default_timezone_set('Asia/Manila');
$now_ph = date('Y-m-d H:i:s'); // Current Philippine date/time

// Find all Pending requests for this agent where the tour date+time has already passed
$expire_find_sql = "
    SELECT tr.tour_id, tr.user_name, tr.user_email, tr.tour_date, tr.tour_time,
           p.StreetAddress, p.City, p.State
    FROM tour_requests tr
    JOIN property p ON tr.property_id = p.property_ID
    WHERE tr.agent_account_id = ?
      AND tr.request_status = 'Pending'
      AND CONCAT(tr.tour_date, ' ', tr.tour_time) < ?";
$stmt = $conn->prepare($expire_find_sql);
$stmt->bind_param('is', $agent_account_id, $now_ph);
$stmt->execute();
$expired_tours = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!empty($expired_tours)) {
    require_once __DIR__ . '/../mail_helper.php';
    
    // Batch update all expired tour requests
    $expire_ids = array_column($expired_tours, 'tour_id');
    $placeholders = implode(',', array_fill(0, count($expire_ids), '?'));
    $types = str_repeat('i', count($expire_ids));
    
    $expire_sql = "UPDATE tour_requests 
                   SET request_status = 'Expired', 
                       expired_at = ?,
                       decision_reason = 'This tour request expired automatically because the scheduled date/time passed without a response.',
                       decision_at = ?
                   WHERE tour_id IN ($placeholders)";
    $stmt = $conn->prepare($expire_sql);
    $params = array_merge([$now_ph, $now_ph], $expire_ids);
    $stmt->bind_param('ss' . $types, ...$params);
    $stmt->execute();
    $stmt->close();
    
    // Send expiry notification email to each user
    foreach ($expired_tours as $exp) {
        $property_address = $exp['StreetAddress'] . ', ' . $exp['City'] . ', ' . $exp['State'];
        $formattedDate = date('F j, Y', strtotime($exp['tour_date']));
        $formattedTime = date('g:i A', strtotime($exp['tour_time']));
        
        try {
            $subject = 'Tour Request Expired - ' . $property_address;
            $body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tour Request Expired</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    <tr>
                        <td style="background:linear-gradient(90deg,#f59e0b 0%,#d97706 50%,#f59e0b 100%);height:3px;"></td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <div style="width:56px;height:56px;border-radius:50%;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;">
                                <span style="font-size:24px;">⏰</span>
                            </div>
                            <h1 style="margin:0 0 12px 0;color:#f59e0b;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Tour Request Expired</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Your scheduled tour date has passed</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;color:#999999;font-size:15px;">
                                Hi <strong style="color:#ffffff;">' . htmlspecialchars($exp['user_name']) . '</strong>,
                            </p>
                            <p style="margin:0 0 32px 0;color:#999999;font-size:14px;line-height:1.8;">
                                We\'re sorry to inform you that your tour request was not confirmed before the scheduled date. The request has been automatically marked as <strong style="color:#f59e0b;">expired</strong>.
                            </p>
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(245,158,11,0.04);border:1px solid rgba(245,158,11,0.15);border-radius:4px;margin-bottom:32px;">
                                <tr>
                                    <td style="padding:28px 24px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:0 0 16px 0;border-bottom:1px solid #1f1f1f;">
                                                    <span style="color:#666666;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Property</span><br>
                                                    <span style="color:#ffffff;font-size:14px;font-weight:500;line-height:1.8;">' . htmlspecialchars($property_address) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:16px 0 0 0;">
                                                    <table width="100%" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td width="50%">
                                                                <span style="color:#666666;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Date</span><br>
                                                                <span style="color:#ffffff;font-size:14px;font-weight:500;line-height:1.8;">' . $formattedDate . '</span>
                                                            </td>
                                                            <td width="50%">
                                                                <span style="color:#666666;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Time</span><br>
                                                                <span style="color:#ffffff;font-size:14px;font-weight:500;line-height:1.8;">' . $formattedTime . '</span>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(37,99,235,0.04);border:1px solid rgba(37,99,235,0.15);border-radius:4px;">
                                <tr>
                                    <td style="padding:24px;">
                                        <p style="margin:0 0 8px 0;color:#3b82f6;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">💡 What You Can Do</p>
                                        <ul style="margin:0;padding:0 0 0 20px;color:#999999;font-size:13px;line-height:2;">
                                            <li>Submit a <strong style="color:#ffffff;">new tour request</strong> with a future date</li>
                                            <li>Try selecting a <strong style="color:#ffffff;">different time slot</strong> that may be more convenient</li>
                                            <li>Contact the agent directly for availability</li>
                                        </ul>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 48px;background:#0d0d0d;border-top:1px solid #1f1f1f;text-align:center;">
                            <p style="margin:0;color:#444444;font-size:12px;">HomeEstate Realty &bull; Automated Notification</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
            sendSystemMail($exp['user_email'], $exp['user_name'], $subject, $body);
        } catch (Exception $e) {
            // Silently log email failure - don't block page load
            error_log("Failed to send expiry email for tour #{$exp['tour_id']}: " . $e->getMessage());
        }
    }
}

// Get agent info for navbar
$agent_info_query = "SELECT ai.*, a.first_name, a.last_name, a.email, a.phone_number, a.date_registered
                     FROM agent_information ai 
                     JOIN accounts a ON ai.account_id = a.account_id 
                     WHERE ai.account_id = ?";
$stmt = $conn->prepare($agent_info_query);
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

// Build SQL for tour requests - load all for real-time filtering
$tours_sql = "
    SELECT tr.*, p.StreetAddress, p.City
    FROM tour_requests tr
    JOIN property p ON tr.property_id = p.property_ID
    WHERE tr.agent_account_id = ?
    ORDER BY tr.requested_at DESC";
$stmt = $conn->prepare($tours_sql);
$stmt->bind_param('i', $agent_account_id);
$stmt->execute();
$tour_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status filtering
$valid_statuses = ['All','Pending','Confirmed','Completed','Cancelled','Rejected','Expired'];
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
  'Expired' => [],
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
  'Expired' => count($requests_by_status['Expired']),
];

// Decide which list to display
switch ($active_status) {
  case 'Pending':
  case 'Confirmed':
  case 'Completed':
  case 'Cancelled':
  case 'Rejected':
  case 'Expired':
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
  <title>Tour Requests - HomeEstate Realty</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    /* ===== DARK THEME VARIABLES ===== */
    :root {
      --gold: #d4af37;
      --gold-light: #f4d03f;
      --gold-dark: #b8941f;
      --blue: #2563eb;
      --blue-light: #3b82f6;
      --blue-dark: #1e40af;
      --black: #0a0a0a;
      --black-light: #111111;
      --black-lighter: #1a1a1a;
      --black-border: #1f1f1f;
      --white: #ffffff;
      --gray-50: #f8f9fa;
      --gray-100: #e8e9eb;
      --gray-200: #d1d4d7;
      --gray-300: #b8bec4;
      --gray-400: #9ca4ab;
      --gray-500: #7a8a99;
      --gray-600: #5d6d7d;
      --gray-700: #3f4b56;
      --gray-800: #2a3138;
      --gray-900: #1a1f24;
      --card-bg: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
      --card-border: rgba(37, 99, 235, 0.15);
      --card-hover-border: rgba(37, 99, 235, 0.35);
      --success: #22c55e;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #06b6d4;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background-color: var(--black);
      color: var(--white);
      line-height: 1.6;
      overflow-x: hidden;
    }

    /* ===== SCROLLBAR ===== */
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: rgba(26, 26, 26, 0.4); }
    ::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, var(--gold), var(--gold-dark));
      border-radius: 4px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: linear-gradient(180deg, var(--gold-light), var(--gold));
    }

    /* ===== MAIN CONTENT ===== */
    .tour-content {
      padding: 2rem;
      max-width: 1440px;
      margin: 0 auto;
    }

    /* ===== PAGE HEADER ===== */
    .page-header {
      background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
      border: 1px solid var(--card-border);
      border-radius: 4px;
      padding: 2rem 2.5rem;
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
    }

    .page-header::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background:
        radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
        radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.04) 0%, transparent 50%);
      pointer-events: none;
    }

    .page-header::after {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 2px;
      background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
    }

    .page-header-inner {
      position: relative;
      z-index: 2;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1.5rem;
    }

    .page-header h1 {
      font-size: 1.75rem;
      font-weight: 800;
      background: linear-gradient(135deg, var(--white) 0%, var(--gray-100) 50%, var(--gold) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 0.25rem;
    }

    .page-header .subtitle {
      color: var(--gray-400);
      font-size: 0.95rem;
    }

    .page-header .header-actions {
      display: flex;
      gap: 0.75rem;
      align-items: center;
    }

    /* ===== BUTTONS ===== */
    .btn-gold {
      background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
      color: #000;
      border: none;
      font-weight: 600;
      font-size: 0.875rem;
      padding: 0.6rem 1.25rem;
      border-radius: 4px;
      transition: all 0.3s ease;
    }

    .btn-gold:hover {
      background: linear-gradient(135deg, var(--gold-light) 0%, var(--gold) 100%);
      color: #000;
      transform: translateY(-2px);
      box-shadow: 0 4px 16px rgba(212, 175, 55, 0.3);
    }

    .btn-dark-outline {
      background: transparent;
      border: 1px solid rgba(255,255,255,0.15);
      color: var(--gray-300);
      font-weight: 500;
      font-size: 0.875rem;
      padding: 0.6rem 1.25rem;
      border-radius: 4px;
      transition: all 0.3s ease;
    }

    .btn-dark-outline:hover {
      border-color: var(--blue);
      color: var(--white);
      background: rgba(37, 99, 235, 0.06);
    }

    /* ===== KPI STAT CARDS ===== */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .kpi-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 4px;
      padding: 1.25rem;
      position: relative;
      overflow: hidden;
      transition: all 0.3s ease;
    }

    .kpi-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 2px;
      background: linear-gradient(90deg, transparent, var(--blue), transparent);
      opacity: 0;
      transition: opacity 0.3s ease;
    }

    .kpi-card:hover {
      border-color: var(--card-hover-border);
      box-shadow: 0 8px 32px rgba(37, 99, 235, 0.12),
                  inset 0 0 20px rgba(37, 99, 235, 0.03);
      transform: translateY(-3px);
    }

    .kpi-card:hover::before { opacity: 1; }

    .kpi-card .kpi-icon {
      width: 40px;
      height: 40px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      margin-bottom: 0.75rem;
    }

    .kpi-icon.gold {
      background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(212, 175, 55, 0.2) 100%);
      color: var(--gold);
      border: 1px solid rgba(212, 175, 55, 0.2);
    }
    .kpi-icon.blue {
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.2) 100%);
      color: var(--blue-light);
      border: 1px solid rgba(37, 99, 235, 0.2);
    }
    .kpi-icon.green {
      background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 197, 94, 0.2) 100%);
      color: #22c55e;
      border: 1px solid rgba(34, 197, 94, 0.2);
    }
    .kpi-icon.amber {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.2) 100%);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.2);
    }
    .kpi-icon.red {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.2) 100%);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .kpi-card .kpi-label {
      font-size: 0.7rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: var(--gray-400);
      margin-bottom: 0.25rem;
    }

    .kpi-card .kpi-value {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--white);
      line-height: 1.2;
    }

    /* ===== FILTER RESULTS BAR ===== */
    .filter-results-bar {
      padding: 0.75rem 0;
      margin-bottom: 1rem;
      min-height: 32px;
    }

    #filterResultsCount {
      font-weight: 600;
      transition: all 0.3s ease;
    }

    .empty-state-filter {
      padding: 4rem 2rem;
      text-align: center;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .request-row {
      transition: opacity 0.2s ease, max-height 0.2s ease;
    }

    /* ===== FILTER SIDEBAR ===== */
    .filter-sidebar {
      position: fixed;
      top: 0;
      right: 0;
      width: 100%;
      height: 100%;
      z-index: 9998;
      pointer-events: none;
    }

    .filter-sidebar.active { pointer-events: all; }

    .filter-sidebar-overlay {
      position: absolute;
      top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(4px);
      opacity: 0;
      transition: opacity 0.3s ease;
      pointer-events: none;
    }

    .filter-sidebar.active .filter-sidebar-overlay {
      opacity: 1;
      pointer-events: all;
    }

    .filter-sidebar-content {
      position: absolute;
      top: 0; right: 0;
      width: 420px;
      max-width: 90vw;
      height: 100%;
      background: var(--black-light);
      border-left: 1px solid var(--card-border);
      box-shadow: -4px 0 40px rgba(0, 0, 0, 0.5);
      transform: translateX(100%);
      transition: transform 0.3s ease;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .filter-sidebar.active .filter-sidebar-content {
      transform: translateX(0);
    }

    .filter-header {
      background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
      padding: 1.25rem 1.75rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid rgba(212, 175, 55, 0.15);
      position: relative;
    }

    .filter-header::after {
      content: '';
      position: absolute;
      bottom: 0; left: 0; right: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
    }

    .filter-header h4 {
      font-weight: 700;
      font-size: 1.1rem;
      color: var(--white);
      display: flex;
      align-items: center;
      margin-bottom: 0;
    }

    .filter-header h4 i {
      color: var(--gold);
    }

    .filter-body {
      flex: 1;
      overflow-y: auto;
      padding: 1.5rem;
    }

    .filter-group {
      margin-bottom: 1.5rem;
    }

    .filter-label {
      display: block;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--gold);
      margin-bottom: 0.5rem;
    }

    .filter-input,
    .filter-select {
      background-color: var(--black-lighter);
      border: 1px solid rgba(255,255,255,0.1);
      color: var(--white);
      border-radius: 4px;
      padding: 0.7rem 1rem;
      font-weight: 500;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      width: 100%;
    }

    .filter-input:focus,
    .filter-select:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15);
      background-color: var(--black-lighter);
      color: var(--white);
      outline: none;
    }

    .filter-select option {
      background-color: var(--black-lighter);
      color: var(--white);
    }

    .filter-input::placeholder {
      color: var(--gray-600);
      font-style: italic;
    }

    .filter-input[type="date"]::-webkit-calendar-picker-indicator {
      filter: invert(1);
      cursor: pointer;
    }

    .filter-section-divider {
      margin: 2rem 0 1.5rem 0;
      padding-top: 1.5rem;
      border-top: 1px solid rgba(255,255,255,0.06);
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--gray-400);
    }

    .filter-section-divider i {
      color: var(--blue-light);
    }

    .filter-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 20px;
      height: 20px;
      padding: 0 0.4rem;
      font-size: 0.7rem;
      font-weight: 700;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--gold), var(--gold-dark));
      color: #000;
      margin-left: 0.5rem;
    }

    .filter-panel .form-label {
      color: var(--gray-400);
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }

    /* ===== STATUS TABS ===== */
    .status-tabs {
      margin-bottom: 2rem;
    }

    .status-tabs .nav-tabs {
      border-bottom: 1px solid rgba(37, 99, 235, 0.1);
      gap: 0.25rem;
    }

    .status-tabs .nav-link {
      border: none;
      border-bottom: 3px solid transparent;
      background: transparent;
      color: var(--gray-400);
      font-weight: 600;
      font-size: 0.9rem;
      padding: 0.85rem 1.25rem;
      transition: all 0.3s ease;
      border-radius: 0;
    }

    .status-tabs .nav-link:hover {
      color: var(--white);
      background: rgba(37, 99, 235, 0.04);
      border-bottom-color: rgba(37, 99, 235, 0.3);
    }

    .status-tabs .nav-link.active {
      color: var(--gold);
      background: rgba(212, 175, 55, 0.04);
      border-bottom-color: var(--gold);
    }

    .status-tabs .tab-count {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 22px;
      height: 22px;
      padding: 0 0.4rem;
      font-size: 0.7rem;
      font-weight: 700;
      border-radius: 4px;
      background: rgba(255,255,255,0.06);
      color: var(--gray-400);
      margin-left: 0.5rem;
    }

    .status-tabs .nav-link.active .tab-count {
      background: rgba(212, 175, 55, 0.15);
      color: var(--gold);
    }

    /* ===== REQUEST LIST ===== */
    .request-list {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 4px;
      overflow: hidden;
    }

    .request-row {
      cursor: pointer;
      border-bottom: 1px solid rgba(255,255,255,0.04);
      padding: 1.5rem 2rem;
      transition: all 0.25s ease;
      position: relative;
    }

    .request-row:last-child { border-bottom: none; }

    .request-row:hover {
      background: rgba(37, 99, 235, 0.04);
      box-shadow: inset 3px 0 0 var(--blue);
    }

    .request-row.unread {
      border-left: 3px solid var(--gold);
      background: rgba(212, 175, 55, 0.03);
    }

    .request-row.unread:hover {
      background: rgba(212, 175, 55, 0.06);
      box-shadow: inset 3px 0 0 var(--gold);
    }

    .request-content {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1.5rem;
    }

    .request-info { flex: 1; min-width: 0; }

    .client-name {
      font-weight: 700;
      font-size: 1.05rem;
      color: var(--white);
      margin-bottom: 0.2rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .client-email {
      color: var(--gray-500);
      font-size: 0.85rem;
      margin-bottom: 0.6rem;
    }

    .request-meta {
      display: flex;
      align-items: center;
      gap: 1.25rem;
      flex-wrap: wrap;
      margin-bottom: 0.4rem;
    }

    .meta-item {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      color: var(--gray-400);
      font-size: 0.8rem;
      font-weight: 500;
    }

    .meta-item i { font-size: 0.75rem; }

    .property-address {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      color: var(--gray-500);
      font-size: 0.8rem;
      font-weight: 500;
    }

    /* ===== STATUS BADGES ===== */
    .status-badge {
      font-size: 0.7rem;
      font-weight: 700;
      padding: 0.4rem 0.85rem;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      border: 1px solid;
      white-space: nowrap;
    }

    .status-pending {
      background: rgba(245, 158, 11, 0.1);
      color: var(--warning);
      border-color: rgba(245, 158, 11, 0.25);
    }

    .status-confirmed {
      background: rgba(34, 197, 94, 0.1);
      color: var(--success);
      border-color: rgba(34, 197, 94, 0.25);
    }

    .status-completed {
      background: rgba(6, 182, 212, 0.1);
      color: var(--info);
      border-color: rgba(6, 182, 212, 0.25);
    }

    .status-cancelled {
      background: rgba(239, 68, 68, 0.1);
      color: var(--danger);
      border-color: rgba(239, 68, 68, 0.25);
    }

    .status-rejected {
      background: rgba(239, 68, 68, 0.1);
      color: var(--danger);
      border-color: rgba(239, 68, 68, 0.25);
    }

    .status-expired {
      background: rgba(156, 163, 175, 0.1);
      color: #9ca3af;
      border-color: rgba(156, 163, 175, 0.25);
    }

    /* ===== TOUR TYPE BADGES ===== */
    .type-badge {
      font-size: 0.65rem;
      font-weight: 700;
      padding: 0.2rem 0.55rem;
      border-radius: 4px;
      letter-spacing: 0.3px;
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      border: 1px solid;
      white-space: nowrap;
    }

    .type-public {
      background: rgba(6, 182, 212, 0.1);
      color: var(--info);
      border-color: rgba(6, 182, 212, 0.25);
    }

    .type-private {
      background: rgba(255, 255, 255, 0.05);
      color: var(--gray-400);
      border-color: rgba(255, 255, 255, 0.1);
    }

    /* ===== UNREAD INDICATOR ===== */
    .unread-dot {
      width: 10px;
      height: 10px;
      background: var(--gold);
      border-radius: 50%;
      display: inline-block;
      box-shadow: 0 0 8px rgba(212, 175, 55, 0.4);
      animation: pulse-gold 2s infinite;
    }

    @keyframes pulse-gold {
      0% { box-shadow: 0 0 0 0 rgba(212, 175, 55, 0.5); }
      70% { box-shadow: 0 0 0 6px rgba(212, 175, 55, 0); }
      100% { box-shadow: 0 0 0 0 rgba(212, 175, 55, 0); }
    }

    .urgent-icon {
      color: var(--warning);
      font-size: 0.85rem;
      animation: pulse-gold 2s infinite;
    }

    /* ===== EMPTY STATE ===== */
    .empty-state {
      padding: 4rem 2rem;
      text-align: center;
    }

    .empty-state .empty-icon {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1.5rem;
      background: rgba(37, 99, 235, 0.06);
      border: 1px solid rgba(37, 99, 235, 0.15);
    }

    .empty-state .empty-icon i {
      font-size: 2rem;
      color: var(--blue);
      opacity: 0.5;
    }

    .empty-state h5 {
      color: var(--white);
      font-weight: 700;
      margin-bottom: 0.5rem;
      font-size: 1.1rem;
    }

    .empty-state p {
      color: var(--gray-500);
      font-size: 0.9rem;
      max-width: 400px;
      margin: 0 auto;
    }

    /* ===== MODALS (DARK THEME) ===== */
    .modal-dark .modal-content {
      background: var(--black-light);
      border: 1px solid var(--card-border);
      border-radius: 8px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    }

    .modal-dark .modal-header {
      background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
      border-bottom: 1px solid rgba(212, 175, 55, 0.15);
      padding: 1.25rem 1.75rem;
      position: relative;
    }

    .modal-dark .modal-header::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
    }

    .modal-dark .modal-title {
      font-weight: 700;
      font-size: 1.1rem;
      color: var(--white);
    }

    .modal-dark .modal-header .btn-close {
      filter: invert(1) brightness(200%);
      opacity: 0.5;
    }

    .modal-dark .modal-header .btn-close:hover { opacity: 0.8; }

    .modal-dark .modal-body {
      padding: 1.75rem;
      background: var(--black-light);
      color: var(--gray-200);
    }

    .modal-dark .modal-footer {
      background: var(--black);
      border-top: 1px solid rgba(255,255,255,0.06);
      padding: 1rem 1.75rem;
    }

    /* Detail card inside modal */
    .modal-status-header {
      background: linear-gradient(135deg, rgba(212, 175, 55, 0.08) 0%, rgba(212, 175, 55, 0.02) 100%);
      border-left: 3px solid var(--gold);
      padding: 1.25rem 1.5rem;
      margin: -1rem -1rem 1.5rem -1rem;
      border-radius: 4px 4px 0 0;
    }

    .modal-status-header.status-pending {
      background: linear-gradient(135deg, rgba(251, 146, 60, 0.08) 0%, rgba(251, 146, 60, 0.02) 100%);
      border-left-color: var(--warning);
    }

    .modal-status-header.status-confirmed {
      background: linear-gradient(135deg, rgba(34, 197, 94, 0.08) 0%, rgba(34, 197, 94, 0.02) 100%);
      border-left-color: var(--success);
    }

    .modal-status-header.status-completed {
      background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(59, 130, 246, 0.02) 100%);
      border-left-color: var(--info);
    }

    .modal-status-header.status-cancelled,
    .modal-status-header.status-rejected {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, rgba(239, 68, 68, 0.02) 100%);
      border-left-color: var(--danger);
    }

    .modal-status-header.status-expired {
      background: linear-gradient(135deg, rgba(156, 163, 175, 0.08) 0%, rgba(156, 163, 175, 0.02) 100%);
      border-left-color: #9ca3af;
    }

    .modal-status-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
    }

    .modal-status-label {
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: var(--gray-500);
      margin-bottom: 0.25rem;
    }

    .modal-status-value {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .details-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
      margin-bottom: 1.5rem;
    }

    @media (max-width: 768px) {
      .details-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
    }

    .detail-card {
      background: rgba(17, 17, 17, 0.6);
      border: 1px solid rgba(212, 175, 55, 0.1);
      border-radius: 6px;
      padding: 1rem 1.25rem;
      transition: all 0.2s ease;
    }

    .detail-card:hover {
      background: rgba(17, 17, 17, 0.8);
      border-color: rgba(212, 175, 55, 0.2);
      transform: translateY(-1px);
    }

    .detail-card-header {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 0.75rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid rgba(212, 175, 55, 0.1);
    }

    .detail-card-icon {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(212, 175, 55, 0.1);
      border-radius: 4px;
      color: var(--gold);
      font-size: 0.875rem;
    }

    .detail-card-label {
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: var(--gray-400);
    }

    .detail-card-content {
      color: var(--gray-100);
      font-size: 0.95rem;
      line-height: 1.6;
    }

    .detail-card-content strong {
      color: var(--gold);
      font-weight: 600;
    }

    .detail-card-content a {
      color: var(--blue-light);
      text-decoration: none;
      transition: color 0.2s ease;
    }

    .detail-card-content a:hover {
      color: var(--gold);
    }

    .detail-card.full-width {
      grid-column: 1 / -1;
    }

    .contact-links {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      margin-top: 0.5rem;
    }

    .contact-link {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem;
      background: rgba(212, 175, 55, 0.05);
      border-radius: 4px;
      font-size: 0.875rem;
      transition: all 0.2s ease;
    }

    .contact-link:hover {
      background: rgba(212, 175, 55, 0.12);
      transform: translateX(3px);
    }

    .contact-link i {
      color: var(--gold);
      font-size: 0.75rem;
      width: 16px;
    }

    .schedule-display {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 0.75rem 1rem;
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(37, 99, 235, 0.02) 100%);
      border-left: 2px solid var(--blue-light);
      border-radius: 4px;
      margin-top: 0.5rem;
    }

    .schedule-item {
      display: flex;
      align-items: center;
      gap: 0.375rem;
    }

    .schedule-item i {
      color: var(--blue-light);
      font-size: 0.875rem;
    }

    .schedule-divider {
      width: 1px;
      height: 20px;
      background: rgba(212, 175, 55, 0.2);
    }

    .reason-box {
      background: rgba(239, 68, 68, 0.08);
      border-left: 3px solid var(--danger);
      padding: 1rem 1.25rem;
      border-radius: 4px;
      color: var(--gray-300);
      line-height: 1.6;
    }

    .message-box {
      background: rgba(17, 17, 17, 0.6);
      border: 1px solid rgba(212, 175, 55, 0.15);
      padding: 1rem 1.25rem;
      border-radius: 4px;
      color: var(--gray-300);
      line-height: 1.7;
      min-height: 80px;
    }

    .message-box:empty::before {
      content: 'No message provided';
      color: var(--gray-500);
      font-style: italic;
    }

    .timestamp-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 0.875rem;
      background: rgba(212, 175, 55, 0.08);
      border: 1px solid rgba(212, 175, 55, 0.15);
      border-radius: 4px;
      font-size: 0.8rem;
      color: var(--gray-300);
      margin-top: 0.5rem;
    }

    .timestamp-badge i {
      color: var(--gold);
      font-size: 0.75rem;
    }

    .timestamp-badge strong {
      color: var(--gold);
      font-weight: 600;
    }

    .section-divider {
      height: 1px;
      background: linear-gradient(90deg, transparent 0%, rgba(212, 175, 55, 0.2) 50%, transparent 100%);
      margin: 1.5rem 0;
    }

    /* Reason modal textarea */
    .modal-dark .form-control {
      background: var(--black-lighter);
      border: 1px solid rgba(255,255,255,0.1);
      color: var(--white);
      border-radius: 4px;
    }

    .modal-dark .form-control:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 0.15rem rgba(37, 99, 235, 0.15);
      background: var(--black-lighter);
      color: var(--white);
    }

    .modal-dark .form-label {
      color: var(--gray-300);
      font-weight: 600;
      font-size: 0.85rem;
    }

    .modal-dark .form-text {
      color: var(--gray-500);
    }

    /* Alerts inside modals */
    .modal-dark .alert-success {
      background: rgba(34, 197, 94, 0.08);
      border: 1px solid rgba(34, 197, 94, 0.2);
      color: var(--success);
    }

    .modal-dark .alert-danger {
      background: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.2);
      color: var(--danger);
    }

    .modal-dark .alert-warning {
      background: rgba(245, 158, 11, 0.08);
      border: 1px solid rgba(245, 158, 11, 0.2);
      color: var(--warning);
    }

    /* ===== CALENDAR SIDEBAR ===== */
    .calendar-sidebar {
      position: fixed;
      top: 0;
      right: 0;
      width: 100%;
      height: 100%;
      z-index: 9999;
      pointer-events: none;
    }

    .calendar-sidebar.active { pointer-events: all; }

    .calendar-sidebar-overlay {
      position: absolute;
      top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(4px);
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
      top: 0; right: 0;
      width: 450px;
      max-width: 90vw;
      height: 100%;
      background: var(--black-light);
      border-left: 1px solid var(--card-border);
      box-shadow: -4px 0 40px rgba(0, 0, 0, 0.5);
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
      background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
      padding: 1.25rem 1.75rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid rgba(212, 175, 55, 0.15);
      position: relative;
    }

    .calendar-header::after {
      content: '';
      position: absolute;
      bottom: 0; left: 0; right: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
    }

    .calendar-header h4 {
      font-weight: 700;
      font-size: 1.1rem;
      color: var(--white);
      display: flex;
      align-items: center;
      margin-bottom: 0;
    }

    .calendar-header h4 i {
      color: var(--gold);
    }

    .btn-close-sidebar {
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.1);
      color: var(--gray-400);
      width: 34px;
      height: 34px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-close-sidebar:hover {
      background: rgba(239, 68, 68, 0.1);
      border-color: rgba(239, 68, 68, 0.3);
      color: var(--danger);
    }

    /* Debug toggle */
    .debug-toggle-container {
      padding: 0.75rem 1.75rem;
      background: rgba(245, 158, 11, 0.04);
      border-bottom: 1px solid rgba(245, 158, 11, 0.1);
    }

    .debug-toggle-container .form-check-label {
      color: var(--gray-400);
      font-weight: 500;
      cursor: pointer;
      font-size: 0.8rem;
    }

    .debug-toggle-container .form-check-input {
      cursor: pointer;
      width: 2.25rem;
      height: 1.15rem;
      background-color: var(--black-lighter);
      border-color: rgba(255,255,255,0.15);
    }

    .debug-toggle-container .form-check-input:checked {
      background-color: var(--gold-dark);
      border-color: var(--gold-dark);
    }

    .calendar-body {
      flex: 1;
      overflow-y: auto;
      padding: 1.5rem;
    }

    /* Calendar widget */
    .calendar-widget {
      background: rgba(26, 26, 26, 0.5);
      border: 1px solid var(--card-border);
      border-radius: 4px;
      padding: 1.25rem;
      margin-bottom: 1.5rem;
    }

    .calendar-controls {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1rem;
    }

    .calendar-controls .btn {
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.1);
      color: var(--gray-400);
      padding: 0.35rem 0.6rem;
      border-radius: 4px;
      transition: all 0.2s ease;
    }

    .calendar-controls .btn:hover {
      background: rgba(212, 175, 55, 0.1);
      border-color: rgba(212, 175, 55, 0.3);
      color: var(--gold);
    }

    .calendar-month-year {
      font-weight: 700;
      color: var(--white);
      font-size: 1rem;
    }

    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 0.2rem;
      margin-bottom: 1rem;
    }

    .calendar-day-header {
      text-align: center;
      font-weight: 700;
      font-size: 0.7rem;
      color: var(--gray-500);
      padding: 0.4rem 0;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .calendar-day {
      aspect-ratio: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
      font-size: 0.825rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
      position: relative;
      color: var(--gray-300);
      border: 1px solid transparent;
    }

    .calendar-day:hover:not(.disabled) {
      background: rgba(37, 99, 235, 0.08);
      border-color: rgba(37, 99, 235, 0.2);
      color: var(--white);
    }

    .calendar-day.disabled {
      color: var(--gray-700);
      cursor: not-allowed;
      opacity: 0.4;
    }

    .calendar-day.today {
      background: rgba(37, 99, 235, 0.12);
      border: 1px solid rgba(37, 99, 235, 0.3);
      color: var(--blue-light);
      font-weight: 800;
    }

    .calendar-day.selected {
      background: linear-gradient(135deg, var(--gold), var(--gold-dark));
      color: #000;
      font-weight: 800;
      border-color: var(--gold);
    }

    .calendar-day.has-tours::after {
      content: '';
      position: absolute;
      bottom: 4px;
      left: 50%;
      transform: translateX(-50%);
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: var(--gold);
    }

    .calendar-day.has-pending::after { background: var(--warning); }
    .calendar-day.has-confirmed::after { background: var(--success); }
    .calendar-day.has-cancelled::after { background: var(--gray-500); }
    .calendar-day.has-rejected::after { background: var(--danger); }
    .calendar-day.has-completed::after { background: var(--info); }
    .calendar-day.has-expired::after { background: #9ca3af; }

    .calendar-legend {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
      padding-top: 0.75rem;
      border-top: 1px solid rgba(255,255,255,0.06);
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.7rem;
      color: var(--gray-400);
      font-weight: 600;
    }

    .legend-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
    }

    /* Scheduled tours section */
    .scheduled-tours-section {
      background: rgba(26, 26, 26, 0.5);
      border: 1px solid var(--card-border);
      border-radius: 4px;
      overflow: hidden;
    }

    .section-header {
      padding: 0.85rem 1.25rem;
      background: rgba(37, 99, 235, 0.04);
      border-bottom: 1px solid rgba(37, 99, 235, 0.1);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .section-header h6 {
      font-weight: 700;
      color: var(--white);
      font-size: 0.85rem;
      margin-bottom: 0;
    }

    .section-header h6 i { color: var(--gold); }

    .section-header .btn {
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.1);
      color: var(--gray-400);
      font-size: 0.75rem;
      padding: 0.25rem 0.65rem;
      border-radius: 4px;
    }

    .section-header .btn:hover {
      color: var(--danger);
      border-color: rgba(239, 68, 68, 0.3);
    }

    .scheduled-tours-list {
      max-height: 400px;
      overflow-y: auto;
    }

    .tour-item {
      padding: 0.85rem 1.25rem;
      border-bottom: 1px solid rgba(255,255,255,0.04);
      border-left: 3px solid transparent;
      transition: all 0.15s ease;
      cursor: pointer;
    }

    .tour-item:last-child { border-bottom: none; }

    .tour-item:hover {
      background: rgba(37, 99, 235, 0.04);
    }

    .tour-item.status-pending { border-left-color: var(--warning); }
    .tour-item.status-confirmed { border-left-color: var(--success); }
    .tour-item.status-cancelled { border-left-color: var(--gray-500); opacity: 0.65; }
    .tour-item.status-rejected { border-left-color: var(--danger); opacity: 0.65; }
    .tour-item.status-completed { border-left-color: var(--info); opacity: 0.75; }
    .tour-item.status-expired { border-left-color: #9ca3af; opacity: 0.5; }

    .tour-item-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.35rem;
    }

    .tour-client-name {
      font-weight: 700;
      color: var(--white);
      font-size: 0.9rem;
    }

    .tour-time {
      display: flex;
      align-items: center;
      gap: 0.3rem;
      color: var(--gray-400);
      font-size: 0.8rem;
      font-weight: 600;
    }

    .tour-time i { color: var(--blue-light); }

    .tour-property {
      color: var(--gray-500);
      font-size: 0.78rem;
      margin-bottom: 0.25rem;
    }

    .tour-conflict-warning {
      background: rgba(245, 158, 11, 0.06);
      border: 1px solid rgba(245, 158, 11, 0.2);
      border-left: 3px solid var(--warning);
      border-radius: 4px;
      padding: 0.5rem 0.75rem;
      margin-top: 0.4rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.75rem;
      color: var(--warning);
      font-weight: 600;
    }

    .tour-item-empty {
      padding: 2.5rem 1.25rem;
      text-align: center;
    }

    .tour-item-empty i {
      font-size: 2.5rem;
      margin-bottom: 1rem;
      color: var(--gray-700);
    }

    .tour-item-empty p {
      font-size: 0.85rem;
      font-weight: 500;
      color: var(--gray-500);
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 1200px) {
      .kpi-grid { grid-template-columns: repeat(3, 1fr); }
    }

    @media (max-width: 768px) {
      .tour-content { padding: 1rem; }
      .page-header { padding: 1.25rem 1.5rem; }
      .page-header-inner { flex-direction: column; align-items: flex-start; }
      .page-header .header-actions { width: 100%; }
      .page-header .header-actions button { flex: 1; }
      .kpi-grid { grid-template-columns: repeat(2, 1fr); }
      .request-row { padding: 1rem 1.25rem; }
      .calendar-sidebar-content { width: 100%; max-width: 100%; }
      .filter-sidebar-content { width: 100%; max-width: 100%; }
    }

    @media (max-width: 480px) {
      .kpi-grid { grid-template-columns: 1fr; }
      .request-meta { gap: 0.75rem; }
      .page-header .header-actions { flex-direction: column; gap: 0.5rem; }
    }
  </style>
</head>
<body>
<?php include 'logout_agent_modal.php'; ?>

<?php 
$active_page = 'agent_tour_requests.php';
include 'agent_navbar.php'; 
?>

<div class="tour-content">
  <!-- PAGE HEADER -->
  <div class="page-header">
    <div class="page-header-inner">
      <div>
        <h1><i class="fas fa-route me-2"></i>Tour Requests</h1>
        <p class="subtitle">Manage and respond to property tour requests from potential clients</p>
      </div>
      <div class="header-actions">
        <button class="btn btn-dark-outline" id="openFiltersBtn">
          <i class="fas fa-filter me-2"></i>Filters & Search
          <span class="filter-badge" id="activeFilterBadge" style="display: none;">0</span>
        </button>
        <button class="btn btn-gold" id="openCalendarBtn">
          <i class="fas fa-calendar-alt me-2"></i>Tour Calendar
        </button>
      </div>
    </div>
  </div>

  <!-- KPI STATS -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-icon gold"><i class="fas fa-inbox"></i></div>
      <div class="kpi-label">Total Requests</div>
      <div class="kpi-value"><?php echo $counts['All']; ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon amber"><i class="fas fa-hourglass-half"></i></div>
      <div class="kpi-label">Awaiting Response</div>
      <div class="kpi-value"><?php echo $counts['Pending']; ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon green"><i class="fas fa-calendar-check"></i></div>
      <div class="kpi-label">Scheduled Tours</div>
      <div class="kpi-value"><?php echo $counts['Confirmed']; ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon blue"><i class="fas fa-clipboard-check"></i></div>
      <div class="kpi-label">Completed</div>
      <div class="kpi-value"><?php echo $counts['Completed']; ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon red"><i class="fas fa-ban"></i></div>
      <div class="kpi-label">Cancelled / Rejected</div>
      <div class="kpi-value"><?php echo $counts['Cancelled'] + $counts['Rejected']; ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon" style="background:rgba(156,163,175,0.08);border-color:rgba(156,163,175,0.2);color:#9ca3af;"><i class="fas fa-hourglass-end"></i></div>
      <div class="kpi-label">Expired</div>
      <div class="kpi-value"><?php echo $counts['Expired']; ?></div>
    </div>
  </div>

  <!-- Filter Results Count - Shown in main area -->
  <div class="filter-results-bar">
    <span id="filterResultsCount" style="color: var(--gray-400); font-size: 0.9rem; font-weight: 600;"></span>
  </div>

  <!-- STATUS TABS -->
  <div class="status-tabs">
    <ul class="nav nav-tabs">
      <?php foreach (['All','Pending','Confirmed','Completed','Cancelled','Rejected','Expired'] as $tab): 
            $active = $active_status === $tab ? 'active' : ''; 
            $count = $counts[$tab];
      ?>
        <li class="nav-item">
          <a class="nav-link <?php echo $active; ?>" href="?status=<?php echo $tab; ?>">
            <?php echo $tab; ?>
            <span class="tab-count"><?php echo (int)$count; ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- REQUEST LIST -->
  <div class="request-list">
    <?php if (empty($display_requests)): ?>
      <div class="empty-state">
        <div class="empty-icon">
          <i class="fas fa-calendar-plus"></i>
        </div>
        <h5>No <?php echo $active_status === 'All' ? '' : strtolower($active_status) . ' '; ?>tour requests</h5>
        <p>
          <?php if ($active_status === 'Pending'): ?>
            You're all caught up! No pending requests require your attention.
          <?php elseif ($active_status === 'Confirmed'): ?>
            No confirmed tours scheduled at the moment.
          <?php elseif ($active_status === 'Completed'): ?>
            No completed tours to display.
          <?php elseif ($active_status === 'Expired'): ?>
            No expired tour requests. Great job staying on top of your responses!
          <?php else: ?>
            When clients request property tours, they'll appear here for your review.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <?php foreach ($display_requests as $req): 
        $isUnread = (int)$req['is_read_by_agent'] === 0;
        $status = $req['request_status'];
        $urgentRequest = (strtotime($req['tour_date']) - time()) < (24 * 60 * 60);
      ?>
        <div class="request-row <?php echo $isUnread ? 'unread' : ''; ?>" data-tour-id="<?php echo (int)$req['tour_id']; ?>">
          <div class="request-content">
            <div class="d-flex align-items-start gap-3">
              <div class="pt-1">
                <?php if ($isUnread): ?>
                  <span class="unread-dot" title="New Request"></span>
                <?php else: ?>
                  <div style="width: 14px;"></div>
                <?php endif; ?>
              </div>
              <div class="request-info">
                <div class="client-name">
                  <?php echo htmlspecialchars($req['user_name']); ?>
                  <?php if ($urgentRequest && $status === 'Pending'): ?>
                    <i class="fas fa-exclamation-triangle urgent-icon" title="Urgent: Tour within 24 hours"></i>
                  <?php endif; ?>
                </div>
                <div class="client-email">
                  <i class="fas fa-envelope me-1" style="font-size:0.75rem;"></i>
                  <?php echo htmlspecialchars($req['user_email']); ?>
                </div>
                <div class="request-meta">
                  <div class="meta-item">
                    <i class="far fa-calendar" style="color:var(--blue-light);"></i>
                    <?php echo date('M j, Y', strtotime($req['tour_date'])); ?>
                  </div>
                  <div class="meta-item">
                    <i class="far fa-clock" style="color:var(--info);"></i>
                    <?php echo date('g:i A', strtotime($req['tour_time'])); ?>
                  </div>
                  <div class="meta-item">
                    <?php if (($req['tour_type'] ?? 'private') === 'public'): ?>
                      <span class="type-badge type-public"><i class="fas fa-users"></i> Public</span>
                    <?php else: ?>
                      <span class="type-badge type-private"><i class="fas fa-user"></i> Private</span>
                    <?php endif; ?>
                  </div>
                  <div class="meta-item">
                    <i class="fas fa-clock" style="color:var(--gray-600);"></i>
                    <?php echo date('M j, g:i A', strtotime($req['requested_at'])); ?>
                  </div>
                </div>
                <div class="property-address">
                  <i class="fas fa-location-dot" style="color:var(--gold-dark);"></i>
                  <?php echo htmlspecialchars($req['StreetAddress'] . ', ' . $req['City']); ?>
                </div>
              </div>
            </div>
            <div class="d-flex flex-column align-items-end gap-2">
              <?php 
                if ($status === 'Completed') {
                  $cls = 'status-completed'; $label = 'Completed'; $icon = '<i class="fas fa-clipboard-check"></i>';
                } elseif ($status === 'Confirmed') {
                  $cls = 'status-confirmed'; $label = 'Confirmed'; $icon = '<i class="fas fa-check"></i>';
                } elseif ($status === 'Cancelled') {
                  $cls = 'status-cancelled'; $label = 'Cancelled'; $icon = '<i class="fas fa-ban"></i>';
                } elseif ($status === 'Rejected') {
                  $cls = 'status-rejected'; $label = 'Rejected'; $icon = '<i class="fas fa-times"></i>';
                } elseif ($status === 'Expired') {
                  $cls = 'status-expired'; $label = 'Expired'; $icon = '<i class="fas fa-hourglass-end"></i>';
                } else {
                  $cls = 'status-pending'; $label = 'Pending'; $icon = '<i class="fas fa-clock"></i>';
                }
              ?>
              <span class="status-badge <?php echo $cls; ?>"><?php echo $icon . ' ' . $label; ?></span>
              <?php if ($status === 'Pending'): ?>
                <small style="color:var(--gray-500); font-size:0.75rem;">
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

<!-- ===== TOUR DETAILS MODAL ===== -->
<div class="modal fade modal-dark" id="tourDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-route me-2" style="color:var(--gold);"></i>Tour Request Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="tourDetailsBody" class="position-relative">
          <div class="d-flex justify-content-center py-4">
            <div class="spinner-border spinner-border-sm" style="color:var(--gold);" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>
        </div>
        <div id="tourDetailsAlert" class="alert d-none mt-3" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn d-none" id="rejectTourBtn" style="background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:var(--danger);font-weight:600;padding:0.6rem 1.25rem;font-size:0.875rem;border-radius:4px;">
          <i class="fas fa-ban me-2"></i>Reject
        </button>
        <button type="button" class="btn d-none" id="cancelTourBtn" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:var(--danger);font-weight:600;padding:0.6rem 1.25rem;font-size:0.875rem;border-radius:4px;">
          <i class="fas fa-xmark me-2"></i>Cancel Tour
        </button>
        <button type="button" class="btn d-none" id="completeTourBtn" style="background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:var(--success);font-weight:600;padding:0.6rem 1.25rem;font-size:0.875rem;border-radius:4px;">
          <i class="fas fa-clipboard-check me-2"></i>Mark Completed
        </button>
        <button type="button" class="btn btn-gold" id="acceptTourBtn" data-tour-id="">
          <i class="fas fa-check me-2"></i>Confirm Schedule
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ===== REASON MODAL ===== -->
<div class="modal fade modal-dark" id="reasonModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="reasonModalTitle">Add Reason</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Provide a brief reason</label>
          <textarea class="form-control" id="reasonText" rows="4" placeholder="Type your reason..." required></textarea>
          <div class="form-text">This will be sent to the client via email.</div>
        </div>
        <div id="reasonAlert" class="alert d-none" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-dark-outline" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-sm d-none" id="submitRejectBtn" style="background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:var(--danger);font-weight:600;">
          <i class="fas fa-ban me-2"></i>Reject Request
        </button>
        <button type="button" class="btn btn-sm d-none" id="submitCancelBtn" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:var(--danger);font-weight:600;">
          <i class="fas fa-xmark me-2"></i>Cancel Tour
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ===== FILTER SIDEBAR ===== -->
<div class="filter-sidebar" id="filterSidebar">
  <div class="filter-sidebar-overlay" id="filterOverlay"></div>
  <div class="filter-sidebar-content">
    <div class="filter-header">
      <h4>
        <i class="fas fa-filter me-2"></i>Filters & Search
      </h4>
      <button class="btn-close-sidebar" id="closeFiltersBtn">
        <i class="fas fa-times"></i>
      </button>
    </div>
    
    <div class="filter-body">
      <!-- Search Bar -->
      <div class="filter-group">
        <label class="filter-label">
          <i class="fas fa-search me-2"></i> Search
        </label>
        <input 
          type="text" 
          id="searchInput" 
          class="filter-input" 
          placeholder="Search by client name, email, phone, or address..."
          autocomplete="off"
        >
      </div>
      
      <!-- Property Filter -->
      <div class="filter-group">
        <label class="filter-label">
          <i class="fas fa-building me-2"></i> Property
        </label>
        <select id="propertyFilter" class="filter-select">
          <option value="">All Properties</option>
          <?php foreach ($properties as $prop): ?>
            <option value="<?php echo (int)$prop['property_ID']; ?>">
              <?php echo htmlspecialchars($prop['title']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <!-- Tour Type Filter -->
      <div class="filter-group">
        <label class="filter-label">
          <i class="fas fa-route me-2"></i> Tour Type
        </label>
        <select id="tourTypeFilter" class="filter-select">
          <option value="">All Types</option>
          <option value="public">Public Tours</option>
          <option value="private">Private Tours</option>
        </select>
      </div>
      
      <!-- Read Status Filter -->
      <div class="filter-group">
        <label class="filter-label">
          <i class="fas fa-eye me-2"></i> Read Status
        </label>
        <select id="readStatusFilter" class="filter-select">
          <option value="">All</option>
          <option value="unread">Unread Only</option>
          <option value="read">Read Only</option>
        </select>
      </div>
      
      <!-- Date Range Section -->
      <div class="filter-section-divider">
        <span><i class="fas fa-calendar-range me-2"></i>Date Range</span>
      </div>
      
      <div class="filter-group">
        <label class="filter-label">
          <i class="fas fa-calendar-day me-2"></i> From Date
        </label>
        <input type="date" id="dateFromFilter" class="filter-input">
      </div>
      
      <div class="filter-group">
        <label class="filter-label">
          <i class="fas fa-calendar-day me-2"></i> To Date
        </label>
        <input type="date" id="dateToFilter" class="filter-input">
      </div>
      
      <!-- Clear Button -->
      <div class="filter-group">
        <button type="button" class="btn btn-dark-outline w-100" id="clearFiltersBtn">
          <i class="fas fa-times me-2"></i>Clear All Filters
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ===== CALENDAR SIDEBAR ===== -->
<div class="calendar-sidebar" id="calendarSidebar">
  <div class="calendar-sidebar-overlay" id="calendarOverlay"></div>
  <div class="calendar-sidebar-content">
    <div class="calendar-header">
      <h4>
        <i class="fas fa-calendar-alt me-2"></i>Tour Calendar
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
          <small>Show All Statuses</small>
        </label>
      </div>
    </div>
    
    <div class="calendar-body">
      <!-- Calendar Widget -->
      <div class="calendar-widget">
        <div class="calendar-controls">
          <button class="btn btn-sm" id="prevMonthBtn">
            <i class="fas fa-chevron-left"></i>
          </button>
          <h5 class="calendar-month-year mb-0" id="calendarMonthYear">January 2025</h5>
          <button class="btn btn-sm" id="nextMonthBtn">
            <i class="fas fa-chevron-right"></i>
          </button>
        </div>
        
        <div class="calendar-grid" id="calendarGrid"></div>
        
        <div class="calendar-legend">
          <div class="legend-item">
            <span class="legend-dot" style="background: var(--warning);"></span>
            <span>Pending</span>
          </div>
          <div class="legend-item">
            <span class="legend-dot" style="background: var(--success);"></span>
            <span>Confirmed</span>
          </div>
          <div class="legend-item debug-only" style="display: none;">
            <span class="legend-dot" style="background: var(--gray-500);"></span>
            <span>Cancelled</span>
          </div>
          <div class="legend-item debug-only" style="display: none;">
            <span class="legend-dot" style="background: var(--danger);"></span>
            <span>Rejected</span>
          </div>
          <div class="legend-item debug-only" style="display: none;">
            <span class="legend-dot" style="background: var(--info);"></span>
            <span>Completed</span>
          </div>
          <div class="legend-item debug-only" style="display: none;">
            <span class="legend-dot" style="background: #9ca3af;"></span>
            <span>Expired</span>
          </div>
        </div>
      </div>
      
      <!-- Scheduled Tours List -->
      <div class="scheduled-tours-section">
        <div class="section-header">
          <h6 id="scheduledToursTitle">
            <i class="fas fa-list me-2"></i>All Scheduled Tours (<span id="tourCount">0</span>)
          </h6>
          <button class="btn btn-sm" id="clearDateFilter" style="display:none;">
            <i class="fas fa-times me-1"></i>Clear
          </button>
        </div>
        
        <div class="scheduled-tours-list" id="scheduledToursList"></div>
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
  
  function normalizeDateKey(value) {
    if (!value) return '';
    return String(value).slice(0, 10);
  }
  
  let debugMode = false;
  
  function toggleDebugMode() {
    debugMode = document.getElementById('showAllStatusesToggle').checked;
    document.querySelectorAll('.legend-item.debug-only').forEach(item => {
      item.style.display = debugMode ? 'flex' : 'none';
    });
    initializeToursByDate();
    renderCalendar();
    renderScheduledTours();
  }
  
  function initializeToursByDate() {
    calendarData.toursByDate = {};
    calendarData.tours.forEach(tour => {
      const status = (tour.request_status || '').toString();
      if (!debugMode && status !== 'Pending' && status !== 'Confirmed') return;
      const dateKey = normalizeDateKey(tour.tour_date);
      if (!dateKey) return;
      if (!calendarData.toursByDate[dateKey]) {
        calendarData.toursByDate[dateKey] = [];
      }
      calendarData.toursByDate[dateKey].push(tour);
    });
  }
  
  // Open/Close Filter Sidebar
  const openFiltersBtn = document.getElementById('openFiltersBtn');
  if (openFiltersBtn) openFiltersBtn.addEventListener('click', () => {
    document.getElementById('filterSidebar').classList.add('active');
  });
  
  const closeFiltersBtn = document.getElementById('closeFiltersBtn');
  if (closeFiltersBtn) closeFiltersBtn.addEventListener('click', () => {
    document.getElementById('filterSidebar').classList.remove('active');
  });
  
  const filterOverlay = document.getElementById('filterOverlay');
  if (filterOverlay) filterOverlay.addEventListener('click', () => {
    document.getElementById('filterSidebar').classList.remove('active');
  });
  
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
  
  document.getElementById('prevMonthBtn').addEventListener('click', () => {
    calendarData.currentDate.setMonth(calendarData.currentDate.getMonth() - 1);
    renderCalendar();
  });
  
  document.getElementById('nextMonthBtn').addEventListener('click', () => {
    calendarData.currentDate.setMonth(calendarData.currentDate.getMonth() + 1);
    renderCalendar();
  });
  
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
    
    const dayHeaders = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    dayHeaders.forEach(day => {
      const header = document.createElement('div');
      header.className = 'calendar-day-header';
      header.textContent = day;
      grid.appendChild(header);
    });
    
    for (let i = firstDayIndex; i > 0; i--) {
      const day = document.createElement('div');
      day.className = 'calendar-day disabled';
      day.textContent = prevLastDay.getDate() - i + 1;
      grid.appendChild(day);
    }
    
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
      
      if (calendarData.toursByDate[dateKey]) {
        const tours = calendarData.toursByDate[dateKey];
        day.classList.add('has-tours');
        
        const hasConfirmed = tours.some(t => t.request_status === 'Confirmed');
        const hasPending = tours.some(t => t.request_status === 'Pending');
        
        if (hasConfirmed) day.classList.add('has-confirmed');
        else if (hasPending) day.classList.add('has-pending');
        
        if (debugMode) {
          const hasCancelled = tours.some(t => t.request_status === 'Cancelled');
          const hasRejected = tours.some(t => t.request_status === 'Rejected');
          const hasCompleted = tours.some(t => t.request_status === 'Completed');
          const hasExpired = tours.some(t => t.request_status === 'Expired');
          if (hasCancelled) day.classList.add('has-cancelled');
          if (hasRejected) day.classList.add('has-rejected');
          if (hasCompleted) day.classList.add('has-completed');
          if (hasExpired) day.classList.add('has-expired');
        }
      }
      
      day.addEventListener('click', () => selectDate(dateKey));
      grid.appendChild(day);
    }
    
    for (let i = 1; i <= nextDays; i++) {
      const day = document.createElement('div');
      day.className = 'calendar-day disabled';
      day.textContent = i;
      grid.appendChild(day);
    }
  }
  
  function selectDate(dateKey) {
    if (calendarData.selectedDate === dateKey) {
      calendarData.selectedDate = null;
      document.getElementById('clearDateFilter').style.display = 'none';
    } else {
      calendarData.selectedDate = dateKey;
      document.getElementById('clearDateFilter').style.display = 'inline-block';
    }
    renderCalendar();
    renderScheduledTours();
  }
  
  document.getElementById('clearDateFilter').addEventListener('click', () => {
    calendarData.selectedDate = null;
    document.getElementById('clearDateFilter').style.display = 'none';
    renderCalendar();
    renderScheduledTours();
  });
  
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
    
    if (!debugMode) {
      toursToShow = toursToShow.filter(t => ['Pending', 'Confirmed'].includes(t.request_status));
    }
    
    count.textContent = toursToShow.length;
    title.innerHTML = `<i class="fas fa-list me-2"></i>${titleText} (<span id="tourCount">${toursToShow.length}</span>)`;
    
    if (toursToShow.length === 0) {
      list.innerHTML = `
        <div class="tour-item-empty">
          <i class="fas fa-calendar-times"></i>
          <p class="mb-0">No tours scheduled for this ${calendarData.selectedDate ? 'date' : 'period'}</p>
        </div>
      `;
      return;
    }
    
    toursToShow.sort((a, b) => {
      const timeA = new Date(`2000-01-01T${a.tour_time}`);
      const timeB = new Date(`2000-01-01T${b.tour_time}`);
      return timeA - timeB;
    });
    
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
      
      if (status === 'Confirmed') { statusClass = 'status-confirmed'; statusIcon = 'fa-check'; }
      else if (status === 'Cancelled') { statusClass = 'status-cancelled'; statusIcon = 'fa-ban'; }
      else if (status === 'Rejected') { statusClass = 'status-rejected'; statusIcon = 'fa-times'; }
      else if (status === 'Completed') { statusClass = 'status-completed'; statusIcon = 'fa-check-double'; }
      else if (status === 'Expired') { statusClass = 'status-expired'; statusIcon = 'fa-hourglass-end'; }
      
      const timeKey = `${tour.tour_date}_${tour.tour_time}`;
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
              ? '<span class="type-badge type-public"><i class="fas fa-users"></i> Public</span>'
              : '<span class="type-badge type-private"><i class="fas fa-user"></i> Private</span>'}
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
  
  function formatTime(timeStr) {
    const date = new Date(`2000-01-01T${timeStr}`);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
  }
  
  function escapeHtml(text) {
    const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    return String(text || '').replace(/[&<>"']/g, m => map[m]);
  }
  
  // ===== END CALENDAR FUNCTIONALITY =====
  
  // ===== REAL-TIME FILTER FUNCTIONALITY =====
  const allTourData = <?php echo json_encode($tour_requests); ?>;
  const currentStatusFilter = '<?php echo $active_status; ?>';
  
  // Debounce function for search input
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }
  
  function applyFilters() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    const propertyFilter = document.getElementById('propertyFilter').value;
    const tourTypeFilter = document.getElementById('tourTypeFilter').value.toLowerCase();
    const readStatusFilter = document.getElementById('readStatusFilter').value;
    const dateFrom = document.getElementById('dateFromFilter').value;
    const dateTo = document.getElementById('dateToFilter').value;
    
    // Count active filters
    let activeFilterCount = 0;
    if (searchTerm) activeFilterCount++;
    if (propertyFilter) activeFilterCount++;
    if (tourTypeFilter) activeFilterCount++;
    if (readStatusFilter) activeFilterCount++;
    if (dateFrom) activeFilterCount++;
    if (dateTo) activeFilterCount++;
    
    // Update filter badge
    const filterBadge = document.getElementById('activeFilterBadge');
    if (activeFilterCount > 0) {
      filterBadge.textContent = activeFilterCount;
      filterBadge.style.display = 'inline-flex';
    } else {
      filterBadge.style.display = 'none';
    }
    
    const allRows = document.querySelectorAll('.request-row');
    let visibleCount = 0;
    
    allRows.forEach(row => {
      const tourId = row.getAttribute('data-tour-id');
      const tourData = allTourData.find(t => String(t.tour_id) === String(tourId));
      
      if (!tourData) {
        row.style.display = 'none';
        return;
      }
      
      // Status filter (from tabs)
      if (currentStatusFilter !== 'All' && tourData.request_status !== currentStatusFilter) {
        row.style.display = 'none';
        return;
      }
      
      // Search filter (name, email, phone, address)
      if (searchTerm) {
        const clientName = (tourData.user_name || '').toLowerCase();
        const clientEmail = (tourData.user_email || '').toLowerCase();
        const clientPhone = (tourData.user_phone || '').toLowerCase();
        const propertyAddress = ((tourData.StreetAddress || '') + ' ' + (tourData.City || '')).toLowerCase();
        
        const matchesSearch = 
          clientName.includes(searchTerm) ||
          clientEmail.includes(searchTerm) ||
          clientPhone.includes(searchTerm) ||
          propertyAddress.includes(searchTerm);
        
        if (!matchesSearch) {
          row.style.display = 'none';
          return;
        }
      }
      
      // Property filter
      if (propertyFilter && String(tourData.property_id) !== String(propertyFilter)) {
        row.style.display = 'none';
        return;
      }
      
      // Tour type filter
      if (tourTypeFilter) {
        const rowTourType = (tourData.tour_type || 'private').toLowerCase();
        if (rowTourType !== tourTypeFilter) {
          row.style.display = 'none';
          return;
        }
      }
      
      // Read status filter
      if (readStatusFilter) {
        const isUnread = parseInt(tourData.is_read_by_agent) === 0;
        if (readStatusFilter === 'unread' && !isUnread) {
          row.style.display = 'none';
          return;
        }
        if (readStatusFilter === 'read' && isUnread) {
          row.style.display = 'none';
          return;
        }
      }
      
      // Date range filter
      const tourDate = tourData.tour_date;
      if (dateFrom && tourDate < dateFrom) {
        row.style.display = 'none';
        return;
      }
      if (dateTo && tourDate > dateTo) {
        row.style.display = 'none';
        return;
      }
      
      // If all filters pass, show the row
      row.style.display = '';
      visibleCount++;
    });
    
    // Update result count
    const filterResultsCount = document.getElementById('filterResultsCount');
    const totalRows = allRows.length;
    
    if (visibleCount === totalRows) {
      filterResultsCount.textContent = `Showing all ${totalRows} request${totalRows !== 1 ? 's' : ''}`;
    } else {
      filterResultsCount.textContent = `Showing ${visibleCount} of ${totalRows} request${totalRows !== 1 ? 's' : ''}`;
    }
    
    // Show empty state if no results
    const requestList = document.querySelector('.request-list');
    const existingEmpty = requestList.querySelector('.empty-state-filter');
    
    if (visibleCount === 0 && allRows.length > 0) {
      if (!existingEmpty) {
        const emptyState = document.createElement('div');
        emptyState.className = 'empty-state empty-state-filter';
        emptyState.innerHTML = `
          <div class="empty-icon">
            <i class="fas fa-search"></i>
          </div>
          <h5>No matching requests found</h5>
          <p>Try adjusting your search or filter criteria</p>
        `;
        requestList.appendChild(emptyState);
      }
    } else {
      if (existingEmpty) {
        existingEmpty.remove();
      }
    }
  }
  
  // Attach event listeners to all filter inputs
  const debouncedFilter = debounce(applyFilters, 300);
  document.getElementById('searchInput').addEventListener('input', debouncedFilter);
  document.getElementById('propertyFilter').addEventListener('change', applyFilters);
  document.getElementById('tourTypeFilter').addEventListener('change', applyFilters);
  document.getElementById('readStatusFilter').addEventListener('change', applyFilters);
  document.getElementById('dateFromFilter').addEventListener('change', applyFilters);
  document.getElementById('dateToFilter').addEventListener('change', applyFilters);
  
  // Clear filters button
  document.getElementById('clearFiltersBtn').addEventListener('click', function() {
    document.getElementById('searchInput').value = '';
    document.getElementById('propertyFilter').value = '';
    document.getElementById('tourTypeFilter').value = '';
    document.getElementById('readStatusFilter').value = '';
    document.getElementById('dateFromFilter').value = '';
    document.getElementById('dateToFilter').value = '';
    applyFilters();
  });
  
  // Initialize filter on page load
  if (document.querySelector('.request-row')) {
    applyFilters();
  }
  
  // ===== END REAL-TIME FILTER =====
  
  // ===== TOUR REQUEST HANDLERS =====
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
            rejectBtn.classList.add('d-none');
            cancelBtn.classList.add('d-none');
            const completeTourBtn = document.getElementById('completeTourBtn');
            completeTourBtn.classList.add('d-none');
            completeTourBtn.dataset.tourId = tourId;
            
            if (data.status && data.status === 'Confirmed') {
              btn.disabled = true;
              btn.innerHTML = '<i class="fas fa-check-double me-2"></i>Already Confirmed';
              btn.classList.remove('btn-gold');
              btn.classList.add('btn-dark-outline');
              btn.style.color = '';
              cancelBtn.classList.remove('d-none');
              rejectBtn.classList.add('d-none');
              completeTourBtn.classList.remove('d-none');
              cancelBtn.dataset.tourId = tourId;
            } else if (data.status && data.status === 'Completed') {
              btn.disabled = true;
              btn.innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Tour Completed';
              btn.classList.remove('btn-gold');
              btn.classList.add('btn-dark-outline');
              btn.style.color = 'var(--info)';
              cancelBtn.classList.add('d-none');
              rejectBtn.classList.add('d-none');
            } else if (data.status && (data.status === 'Cancelled' || data.status === 'Rejected')) {
              btn.disabled = true;
              btn.innerHTML = data.status === 'Rejected' ? '<i class="fas fa-ban me-2"></i>Rejected' : '<i class="fas fa-ban me-2"></i>Cancelled';
              btn.classList.remove('btn-gold');
              btn.classList.add('btn-dark-outline');
              btn.style.color = '';
              cancelBtn.classList.add('d-none');
              rejectBtn.classList.add('d-none');
            } else if (data.status && data.status === 'Expired') {
              btn.disabled = true;
              btn.innerHTML = '<i class="fas fa-hourglass-end me-2"></i>Expired';
              btn.classList.remove('btn-gold');
              btn.classList.add('btn-dark-outline');
              btn.style.color = '#9ca3af';
              cancelBtn.classList.add('d-none');
              rejectBtn.classList.add('d-none');
            } else {
              btn.disabled = false;
              btn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
              btn.classList.add('btn-gold');
              btn.classList.remove('btn-dark-outline');
              btn.style.color = '';
              cancelBtn.classList.add('d-none');
              rejectBtn.classList.remove('d-none');
              rejectBtn.dataset.tourId = tourId;
            }
          } else {
            body.innerHTML = '<div style="color:var(--danger);">'+(data.message || 'Failed to load details')+'</div>';
            btn.disabled = true;
            if (rejectBtn) rejectBtn.classList.add('d-none');
            if (cancelBtn) cancelBtn.classList.add('d-none');
          }
          modal.show();

          // Update read status in real-time
          const dot = row.querySelector('.unread-dot');
          if (dot) {
            // Remove unread visual indicators
            dot.remove();
            row.classList.remove('unread');
            
            // Add placeholder space where dot was
            const placeholder = document.createElement('div');
            placeholder.style.width = '14px';
            dot.parentElement.appendChild(placeholder);
            
            // Update allTourData array
            const tourDataIndex = allTourData.findIndex(t => String(t.tour_id) === String(tourId));
            if (tourDataIndex !== -1) {
              allTourData[tourDataIndex].is_read_by_agent = 1;
            }
            
            // Reapply filters to update filtered view
            applyFilters();
          }
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
      
      if (conflictData.has_exact_conflict) {
        alertBox.classList.remove('d-none', 'alert-success');
        alertBox.classList.add('alert-danger');
        alertBox.innerHTML = `
          <div class="d-flex align-items-start gap-2">
            <i class="fas fa-ban mt-1"></i>
            <div><strong>Cannot Confirm:</strong><br>${conflictData.message}</div>
          </div>
        `;
        acceptBtn.disabled = false;
        acceptBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Schedule';
        return;
      }

      if (conflictData.group_public_notice) {
        alertBox.classList.remove('d-none', 'alert-danger', 'alert-success');
        alertBox.classList.add('alert-warning');
        const msg = conflictData.group_public_message || 'Another public tour for this property/time is already confirmed. This request will be grouped.';
        alertBox.innerHTML = `
          <div class="d-flex align-items-start gap-2 mb-3">
            <i class="fas fa-users mt-1"></i>
            <div><strong>Group Tour Notice:</strong><br>${msg}</div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-dark-outline" id="cancelConfirmBtn">Cancel</button>
            <button class="btn btn-sm btn-gold" id="proceedConfirmBtn">Proceed Anyway</button>
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
      
      if (conflictData.has_same_day_conflict) {
        alertBox.classList.remove('d-none', 'alert-danger', 'alert-success');
        alertBox.classList.add('alert-warning');
        alertBox.innerHTML = `
          <div class="d-flex align-items-start gap-2 mb-3">
            <i class="fas fa-exclamation-triangle mt-1"></i>
            <div><strong>Schedule Warning:</strong><br>${conflictData.message}</div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-dark-outline" id="cancelConfirmBtn">Cancel</button>
            <button class="btn btn-sm btn-gold" id="proceedConfirmBtn">Proceed Anyway</button>
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
        
        const row = document.querySelector('.request-row[data-tour-id="'+tourId+'"]');
        if (row) {
          const badge = row.querySelector('.status-badge');
          if (badge) { 
            badge.className = 'status-badge status-confirmed'; 
            badge.innerHTML = '<i class="fas fa-check me-1"></i>Confirmed'; 
          }
        }
        
        acceptBtn.disabled = true;
        acceptBtn.innerHTML = '<i class="fas fa-check-double me-2"></i>Already Confirmed';
        acceptBtn.classList.remove('btn-gold');
        acceptBtn.classList.add('btn-dark-outline');
        
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
  let reasonAction = null;
  let reasonTourId = null;

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
          const listRow = document.querySelector('.request-row[data-tour-id="'+reasonTourId+'"]');
          if (listRow) {
            const badge = listRow.querySelector('.status-badge');
            if (badge) {
              badge.className = 'status-badge status-rejected';
              badge.innerHTML = '<i class="fas fa-ban me-1"></i>Rejected';
            }
          }
          document.getElementById('acceptTourBtn').disabled = true;
          document.getElementById('acceptTourBtn').innerHTML = '<i class="fas fa-ban me-2"></i>Rejected';
          document.getElementById('acceptTourBtn').classList.remove('btn-gold');
          document.getElementById('acceptTourBtn').classList.add('btn-dark-outline');
          document.getElementById('rejectTourBtn').classList.add('d-none');
          document.getElementById('cancelTourBtn').classList.add('d-none');

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
          .catch(() => {});

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
    
    const alertBox = document.getElementById('tourDetailsAlert');
    alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');
    alertBox.classList.add('alert-warning');
    alertBox.innerHTML = `
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <i class="fas fa-question-circle me-2"></i>
          Are you sure you want to mark this tour as completed?
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-dark-outline" id="cancelCompleteTourBtn">No, Cancel</button>
          <button class="btn btn-sm btn-gold" id="confirmCompleteTourBtn">Yes, Complete</button>
        </div>
      </div>
    `;
    
    document.getElementById('cancelCompleteTourBtn').addEventListener('click', function() {
      alertBox.classList.add('d-none');
    });
    
    document.getElementById('confirmCompleteTourBtn').addEventListener('click', function() {
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
        alertBox.classList.remove('d-none', 'alert-warning', 'alert-danger');
        
        if (data.success) {
          alertBox.classList.add('alert-success');
          alertBox.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + (data.message || 'Tour marked as completed successfully.');
          
          const row = document.querySelector('.request-row[data-tour-id="'+tourId+'"]');
          if (row) {
            const badge = row.querySelector('.status-badge');
            if (badge) {
              badge.className = 'status-badge status-completed';
              badge.innerHTML = '<i class="fas fa-clipboard-check me-1"></i>Completed';
            }
          }
          
          document.getElementById('acceptTourBtn').disabled = true;
          document.getElementById('acceptTourBtn').innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Tour Completed';
          document.getElementById('acceptTourBtn').classList.remove('btn-gold');
          document.getElementById('acceptTourBtn').classList.add('btn-dark-outline');
          document.getElementById('acceptTourBtn').style.color = 'var(--info)';
          
          document.getElementById('cancelTourBtn').classList.add('d-none');
          document.getElementById('completeTourBtn').classList.add('d-none');
          
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
          .catch(() => {});
        } else {
          alertBox.classList.add('alert-danger');
          alertBox.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>' + (data.message || 'Failed to mark tour as completed.');
          document.getElementById('completeTourBtn').disabled = false;
          document.getElementById('completeTourBtn').innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Mark Completed';
        }
      })
      .catch(err => {
        console.error(err);
        alertBox.classList.remove('alert-warning');
        alertBox.classList.add('alert-danger');
        alertBox.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Unexpected error. Please try again.';
        document.getElementById('completeTourBtn').disabled = false;
        document.getElementById('completeTourBtn').innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Mark Completed';
        });
      });
    });
</script>
</body>
</html>
