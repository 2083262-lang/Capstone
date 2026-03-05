<?php
session_start();
require_once 'connection.php';
require_once 'mail_helper.php';
require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/config/paths.php';

// Admin-only access
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
date_default_timezone_set('Asia/Manila');

$success_message = '';
$error_message   = '';

// ===== HANDLE APPROVE ACTION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve' && !empty($_POST['verification_id'])) {
    $verification_id = (int)$_POST['verification_id'];
    $conn->begin_transaction();
    try {
        // Lock and fetch the verification — must still be Pending
        $sql = "SELECT sv.*, p.ListingPrice, p.StreetAddress, p.City, p.PropertyType
                FROM sale_verifications sv
                JOIN property p ON p.property_ID = sv.property_id
                WHERE sv.verification_id = ? FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $verification_id);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$v) throw new Exception('Sale verification not found.');
        if ($v['status'] !== 'Pending') throw new Exception('Only pending verifications can be approved. Current status: ' . $v['status']);

        $property_id = (int)$v['property_id'];

        // 1) Update verification → Approved
        $u = $conn->prepare("UPDATE sale_verifications SET status='Approved', reviewed_by=?, reviewed_at=NOW() WHERE verification_id=?");
        $u->bind_param('ii', $_SESSION['account_id'], $verification_id);
        $u->execute(); $u->close();

        // 2) Update property → Sold & locked
        $u = $conn->prepare("UPDATE property SET Status='Sold', is_locked=1, sold_date=?, sold_by_agent=? WHERE property_ID=?");
        $u->bind_param('sii', $v['sale_date'], $v['agent_id'], $property_id);
        $u->execute(); $u->close();

        // 3) Determine buyer email
        $buyerEmail = null;
        if (!empty($v['buyer_email'])) {
            $buyerEmail = $v['buyer_email'];
        } else {
            $tr = $conn->prepare("SELECT user_email FROM tour_requests WHERE property_id=? ORDER BY requested_at DESC LIMIT 1");
            $tr->bind_param('i', $property_id); $tr->execute();
            $row = $tr->get_result()->fetch_assoc(); $tr->close();
            if ($row) $buyerEmail = $row['user_email'] ?? null;
        }

        // 4) Create finalized_sales record (commission handled separately in finalize step)
        $ins = $conn->prepare("INSERT INTO finalized_sales
            (verification_id, property_id, agent_id, buyer_name, buyer_email, final_sale_price, sale_date, additional_notes, finalized_by)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->bind_param('iiissdss' . 'i',
            $verification_id, $property_id, $v['agent_id'],
            $v['buyer_name'], $buyerEmail,
            $v['sale_price'], $v['sale_date'], $v['additional_notes'],
            $_SESSION['account_id']);
        $ins->execute(); $ins->close();

        // 5) Audit logs
        $l = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?,'property','approved','Sale verification approved – property marked as sold',?)");
        $l->bind_param('ii', $property_id, $_SESSION['account_id']); $l->execute(); $l->close();

        $l = $conn->prepare("INSERT INTO price_history (property_id, event_date, event_type, price) VALUES (?, CURDATE(), 'Sold', ?)");
        $l->bind_param('id', $property_id, $v['sale_price']); $l->execute(); $l->close();

        $msg = 'Sale approved via verification #' . $verification_id;
        $l = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message, reference_id) VALUES (?,?,'SOLD',NOW(),?,?)");
        $l->bind_param('iisi', $property_id, $_SESSION['account_id'], $msg, $verification_id); $l->execute(); $l->close();

        // 6) Admin notification
        $fi = "Property sale approved – Commission pending for Property #$property_id, Agent #{$v['agent_id']}, Price: ₱" . number_format($v['sale_price'], 2);
        $n = $conn->prepare("INSERT INTO notifications (item_id, item_type, message, created_at) VALUES (?,'property_sale',?,NOW())");
        $n->bind_param('is', $verification_id, $fi); $n->execute(); $n->close();

        // 7) Agent notification
        require_once __DIR__ . '/agent_pages/agent_notification_helper.php';
        createAgentNotification($conn, (int)$v['agent_id'], 'sale_approved', 'Sale Approved',
            "Your sale for Property #$property_id has been approved! Sale price: ₱" . number_format($v['sale_price'], 2) . ". Commission will be processed shortly.",
            $verification_id);

        $conn->commit();

        // ===== SEND EMAILS (best-effort, after commit) =====
        $propAddr = $v['StreetAddress'] . ', ' . $v['City'];
        $agentSql = $conn->prepare("SELECT first_name, last_name, email FROM accounts WHERE account_id=?");
        $agentSql->bind_param('i', $v['agent_id']); $agentSql->execute();
        $ag = $agentSql->get_result()->fetch_assoc(); $agentSql->close();
        $agentName  = ($ag['first_name'] ?? '') . ' ' . ($ag['last_name'] ?? '');
        $agentEmail = $ag['email'] ?? '';
        $fmtPrice = '₱' . number_format($v['sale_price'], 2);
        $fmtDate  = date('F j, Y', strtotime($v['sale_date']));

        // Email to agent
        if ($agentEmail) {
            $body = buildApprovalEmailAgent($agentName, $propAddr, $v['PropertyType'], $fmtPrice, $fmtDate, $v['buyer_name']);
            sendSystemMail($agentEmail, $agentName, 'Property Sale Approved – Congratulations!', $body, 'Your property sale has been approved.');
        }
        // Email to buyer
        if ($buyerEmail) {
            $body = buildApprovalEmailBuyer($v['buyer_name'], $propAddr, $v['PropertyType'], $fmtPrice, $fmtDate, $agentName);
            sendSystemMail($buyerEmail, $v['buyer_name'], 'Property Purchase Confirmed', $body, 'Your property purchase has been confirmed.');
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=approved');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = 'Error approving: ' . $e->getMessage();
    }
}

// ===== HANDLE REJECT ACTION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject' && !empty($_POST['verification_id']) && isset($_POST['reason'])) {
    $verification_id = (int)$_POST['verification_id'];
    $reason = trim($_POST['reason']);
    if ($reason === '') { $error_message = 'Rejection reason is required.'; }
    else {
        $conn->begin_transaction();
        try {
            // Lock and fetch
            $sql = "SELECT sv.*, p.StreetAddress, p.City, p.PropertyType, p.property_ID
                    FROM sale_verifications sv
                    JOIN property p ON p.property_ID = sv.property_id
                    WHERE sv.verification_id = ? FOR UPDATE";
            $stmt = $conn->prepare($sql); $stmt->bind_param('i', $verification_id); $stmt->execute();
            $v = $stmt->get_result()->fetch_assoc(); $stmt->close();

            if (!$v) throw new Exception('Sale verification not found.');
            if ($v['status'] !== 'Pending') throw new Exception('Only pending verifications can be rejected. Current status: ' . $v['status']);

            $property_id = (int)$v['property_ID'];

            // 1) Reject verification
            $u = $conn->prepare("UPDATE sale_verifications SET status='Rejected', admin_notes=?, reviewed_by=?, reviewed_at=NOW() WHERE verification_id=?");
            $u->bind_param('sii', $reason, $_SESSION['account_id'], $verification_id); $u->execute(); $u->close();

            // 2) Revert property to For Sale
            $u = $conn->prepare("UPDATE property SET Status='For Sale', is_locked=0 WHERE property_ID=?");
            $u->bind_param('i', $property_id); $u->execute(); $u->close();

            // 3) Audit logs
            $l = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?,'property','rejected',?,?)");
            $l->bind_param('isi', $verification_id, $reason, $_SESSION['account_id']); $l->execute(); $l->close();

            $l = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message, reference_id) VALUES (?,?,'REJECTED',NOW(),?,?)");
            $l->bind_param('iisi', $property_id, $_SESSION['account_id'], $reason, $verification_id); $l->execute(); $l->close();

            // 4) Agent notification
            if (!function_exists('createAgentNotification')) require_once __DIR__ . '/agent_pages/agent_notification_helper.php';
            createAgentNotification($conn, (int)$v['agent_id'], 'sale_rejected', 'Sale Rejected',
                "Your sale verification for {$v['StreetAddress']}, {$v['City']} was rejected. Reason: $reason",
                $verification_id);

            $conn->commit();

            // Emails (best-effort)
            $propAddr = $v['StreetAddress'] . ', ' . $v['City'];
            $agSql = $conn->prepare("SELECT first_name, last_name, email FROM accounts WHERE account_id=?");
            $agSql->bind_param('i', $v['agent_id']); $agSql->execute();
            $ag = $agSql->get_result()->fetch_assoc(); $agSql->close();
            $agentName  = ($ag['first_name'] ?? '') . ' ' . ($ag['last_name'] ?? '');
            $agentEmail = $ag['email'] ?? '';

            if ($agentEmail) {
                $body = buildRejectionEmailAgent($agentName, $propAddr, $v['PropertyType'], '₱' . number_format($v['sale_price'], 2), $v['buyer_name'], $reason);
                sendSystemMail($agentEmail, $agentName, 'Sale Verification Rejected – Action Required', $body, 'Your sale verification was rejected.');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=rejected');
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = 'Error rejecting: ' . $e->getMessage();
        }
    }
}

// ===== SUCCESS MESSAGES =====
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'approved':        $success_message = 'Sale verification approved! Property marked as SOLD.'; break;
        case 'rejected':        $success_message = 'Sale verification rejected successfully.'; break;
        case 'finalized':       $success_message = 'Sale finalized and commission calculated.'; break;
        case 'payment_processed': $success_message = 'Commission payment has been processed and marked as paid.'; break;
    }
}

// ===== EMAIL BUILDER FUNCTIONS =====
function buildApprovalEmailAgent($name, $address, $type, $price, $date, $buyer) {
    $bodyContent  = emailGreeting($name);
    $bodyContent .= emailParagraph('Your property sale verification has been <strong style="color:#22c55e;">approved</strong> by the admin team.');
    $bodyContent .= emailInfoCard('Sale Details', [
        'Property'   => htmlspecialchars($address),
        'Type'       => htmlspecialchars($type),
        'Sale Price' => '<span style="color:#22c55e;font-weight:700;">' . $price . '</span>',
        'Sale Date'  => $date,
        'Buyer'      => htmlspecialchars($buyer),
    ]);
    $bodyContent .= emailNotice("What's Next", 'The property has been marked as SOLD. Commission processing will follow shortly. This sale will be reflected in your dashboard.', '#2563eb');
    return buildEmailTemplate([
        'accentColor' => '#22c55e',
        'heading'     => 'Sale Approved',
        'subtitle'    => 'Congratulations on the successful sale!',
        'body'        => $bodyContent,
    ]);
}

function buildApprovalEmailBuyer($name, $address, $type, $price, $date, $agent) {
    $bodyContent  = emailGreeting($name, 'Dear');
    $bodyContent .= emailParagraph('Your property purchase has been officially <strong style="color:#22c55e;">confirmed</strong>. Welcome to your new home!');
    $bodyContent .= emailInfoCard('Property Details', [
        'Address'        => htmlspecialchars($address),
        'Type'           => htmlspecialchars($type),
        'Purchase Price' => '<span style="color:#d4af37;font-weight:700;">' . $price . '</span>',
        'Sale Date'      => $date,
        'Your Agent'     => htmlspecialchars($agent),
    ]);
    $bodyContent .= emailNotice('Next Steps', 'Your agent will contact you to finalize documentation. Ensure all legal paperwork is completed. Schedule your property handover and key collection.', '#22c55e');
    return buildEmailTemplate([
        'accentColor' => '#d4af37',
        'heading'     => 'Purchase Confirmed',
        'subtitle'    => 'Congratulations on your new property!',
        'body'        => $bodyContent,
    ]);
}

function buildRejectionEmailAgent($name, $address, $type, $price, $buyer, $reason) {
    $bodyContent  = emailGreeting($name);
    $bodyContent .= emailParagraph('Your sale verification has been <strong style="color:#ef4444;">rejected</strong>. Please review the details below.');
    $bodyContent .= emailInfoCard('Submission Details', [
        'Property'   => htmlspecialchars($address),
        'Type'       => htmlspecialchars($type),
        'Sale Price' => $price,
        'Buyer'      => htmlspecialchars($buyer),
    ]);
    $bodyContent .= emailNotice('Rejection Reason', htmlspecialchars($reason), '#ef4444');
    $bodyContent .= emailNotice('What To Do', 'Review the rejection reason, address the issues, gather correct documentation, and resubmit the sale verification with accurate details.', '#2563eb');
    return buildEmailTemplate([
        'accentColor' => '#ef4444',
        'heading'     => 'Sale Rejected',
        'subtitle'    => 'Your sale verification requires attention',
        'body'        => $bodyContent,
    ]);
}

// ===== FETCH ALL SALE VERIFICATIONS =====
$sale_verifications = [];
$status_counts = ['All' => 0, 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0];

$sql = "
    SELECT
        sv.*,
        p.StreetAddress, p.City, p.property_ID, p.PropertyType, p.ListingPrice,
        a.first_name AS agent_first_name, a.last_name AS agent_last_name, a.email AS agent_email,
        (SELECT pi.PhotoURL FROM property_images pi WHERE pi.property_ID = p.property_ID ORDER BY pi.SortOrder ASC LIMIT 1) as property_image,
        (SELECT COUNT(*) FROM property_images pi WHERE pi.property_ID = p.property_ID) as property_image_count,
        (SELECT GROUP_CONCAT(
            CONCAT('{\"url\":\"', REPLACE(pi.PhotoURL, '\"', '\\\\\"'), '\",\"sort_order\":', COALESCE(pi.SortOrder, 0), '}')
            ORDER BY pi.SortOrder ASC SEPARATOR '|||')
         FROM property_images pi WHERE pi.property_ID = p.property_ID) as property_images_json,
        (SELECT COUNT(*) FROM sale_verification_documents svd WHERE svd.verification_id = sv.verification_id) as document_count,
        (SELECT GROUP_CONCAT(
            CONCAT('{\"id\":', svd.document_id, ',\"original_filename\":\"', REPLACE(svd.original_filename, '\"', '\\\\\"'), '\",\"stored_filename\":\"', REPLACE(svd.stored_filename, '\"', '\\\\\"'), '\",\"file_path\":\"', REPLACE(svd.file_path, '\"', '\\\\\"'), '\",\"file_size\":', COALESCE(svd.file_size, 0), ',\"mime_type\":\"', COALESCE(svd.mime_type, ''), '\",\"uploaded_at\":\"', svd.uploaded_at, '\"}')
            SEPARATOR '|||')
         FROM sale_verification_documents svd WHERE svd.verification_id = sv.verification_id) as documents_json,
        fs.sale_id AS finalized_sale_id,
        fs.buyer_email AS finalized_buyer_email,
        ac.commission_id, ac.commission_amount, ac.commission_percentage, ac.status AS commission_status,
        ac.paid_at AS commission_paid_at, ac.paid_by AS commission_paid_by,
        ac.payment_method AS commission_payment_method, ac.payment_reference AS commission_payment_ref,
        ac.payment_proof_path AS commission_proof_path, ac.payment_notes AS commission_payment_notes,
        pb.first_name AS paid_by_first, pb.last_name AS paid_by_last
    FROM sale_verifications sv
    LEFT JOIN property p ON p.property_ID = sv.property_id
    LEFT JOIN accounts a ON a.account_id = sv.agent_id
    LEFT JOIN finalized_sales fs ON fs.verification_id = sv.verification_id
    LEFT JOIN agent_commissions ac ON ac.sale_id = fs.sale_id
    LEFT JOIN accounts pb ON pb.account_id = ac.paid_by
    ORDER BY
        CASE sv.status WHEN 'Pending' THEN 1 WHEN 'Approved' THEN 2 WHEN 'Rejected' THEN 3 ELSE 4 END,
        sv.submitted_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $st = $row['status'] ?: 'Pending';
    $status_counts[$st] = ($status_counts[$st] ?? 0) + 1;
    $status_counts['All']++;

    $row['sale_date_fmt']    = $row['sale_date'] ? date('M j, Y', strtotime($row['sale_date'])) : '';
    $row['submitted_at_fmt'] = $row['submitted_at'] ? date('M j, Y g:i A', strtotime($row['submitted_at'])) : '';
    $row['reviewed_at_fmt']  = $row['reviewed_at'] ? date('M j, Y g:i A', strtotime($row['reviewed_at'])) : '';

    // Parse documents
    $row['documents'] = [];
    if ($row['documents_json']) {
        foreach (explode('|||', $row['documents_json']) as $ds) {
            $d = json_decode($ds, true);
            if ($d) $row['documents'][] = $d;
        }
    }
    // Parse property images
    $row['property_images'] = [];
    if ($row['property_images_json']) {
        foreach (explode('|||', $row['property_images_json']) as $is) {
            $i = json_decode($is, true);
            if ($i) $row['property_images'][] = $i;
        }
    }

    $sale_verifications[] = $row;
}
$stmt->close();

// ===== COMMISSION MANAGEMENT DATA =====
$commissions_for_management = array_filter($sale_verifications, function($s) {
    return $s['status'] === 'Approved'
        && !empty($s['commission_amount'])
        && ($s['commission_status'] ?? '') !== 'paid';
});
$commissions_paid = array_filter($sale_verifications, function($s) {
    return $s['status'] === 'Approved'
        && !empty($s['commission_amount'])
        && ($s['commission_status'] ?? '') === 'paid';
});
$commission_stats = [
    'total_finalized' => count(array_filter($sale_verifications, fn($s) => $s['status'] === 'Approved' && !empty($s['commission_amount']))),
    'awaiting'        => count($commissions_for_management),
    'paid'            => count($commissions_paid),
    'total_unpaid_amount' => array_sum(array_map(fn($s) => (float)($s['commission_amount'] ?? 0), array_values($commissions_for_management))),
    'total_paid_amount'   => array_sum(array_map(fn($s) => (float)($s['commission_amount'] ?? 0), array_values($commissions_paid))),
];

// Status tabs config
$status_tabs = [
    'All'      => ['icon' => 'bi-grid-3x3-gap-fill', 'count' => $status_counts['All']],
    'Pending'  => ['icon' => 'bi-clock-history',      'count' => $status_counts['Pending']],
    'Approved' => ['icon' => 'bi-check-circle-fill',  'count' => $status_counts['Approved']],
    'Rejected' => ['icon' => 'bi-x-circle-fill',      'count' => $status_counts['Rejected']],
];
$active_status = isset($_GET['status']) && array_key_exists($_GET['status'], $status_tabs) ? $_GET['status'] : 'All';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Property Sale Approvals - Admin Panel</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    <style>
        /* ===== GLOBAL LAYOUT (matches property.php exactly) ===== */
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
            /* Theme tokens — defined at :root so Bootstrap modals (rendered under <body>) can access them */
            --gold: #d4af37; --gold-light: #f4d03f; --gold-dark: #b8941f;
            --blue: #2563eb; --blue-light: #3b82f6; --blue-dark: #1e40af;
            --card-bg: #ffffff; --text-primary: #212529; --text-secondary: #6c757d;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-light); color: #212529; }
        .admin-sidebar { background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%); color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; z-index: 1000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .admin-content { margin-left: 290px; padding: 2rem; min-height: 100vh; max-width: 1800px; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; padding: 1.5rem; } }
        @media (max-width: 768px)  { .admin-content { margin-left: 0 !important; padding: 1rem; } }

        /* ===== PAGE HEADER (matches property.php) ===== */
        .page-header { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(ellipse at top right, rgba(37,99,235,0.04) 0%, transparent 50%), radial-gradient(ellipse at bottom left, rgba(212,175,55,0.03) 0%, transparent 50%); pointer-events: none; }
        .page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; }
        .page-header .subtitle { font-size: 0.95rem; color: var(--text-secondary); font-weight: 400; }

        /* ===== KPI STAT CARDS (matches property.php) ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem; cursor: default; transition: all 0.2s ease; position: relative; overflow: hidden; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, rgba(212,175,55,0.03), rgba(37,99,235,0.02)); opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card .kpi-icon { width: 48px; height: 48px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
        .kpi-icon.gold   { background: rgba(212,175,55,0.1);  color: var(--gold); }
        .kpi-icon.amber  { background: rgba(245,158,11,0.1);  color: #d97706; }
        .kpi-icon.green  { background: rgba(34,197,94,0.1);   color: #16a34a; }
        .kpi-icon.red    { background: rgba(239,68,68,0.1);   color: #dc2626; }
        .kpi-card .kpi-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.125rem; }
        .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); }

        /* ===== STATUS TABS (matches property.php tabs) ===== */
        .sale-tabs { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; position: relative; }
        .sale-tabs::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .sale-tabs .nav-tabs { border: none; padding: 0 1rem; margin: 0; }
        .sale-tabs .nav-item { margin: 0; }
        .sale-tabs .nav-link { border: none; border-radius: 0; padding: 1rem 1.25rem; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); background: transparent; transition: all 0.2s ease; display: flex; align-items: center; gap: 0.5rem; border-bottom: 2px solid transparent; }
        .sale-tabs .nav-link:hover { color: var(--text-primary); background: rgba(37,99,235,0.03); }
        .sale-tabs .nav-link.active { color: var(--gold-dark); border-bottom-color: var(--gold); background: rgba(212,175,55,0.04); }
        .tab-badge { font-size: 0.7rem; padding: 0.15rem 0.5rem; border-radius: 10px; font-weight: 700; }
        .badge-all      { background: rgba(212,175,55,0.1); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.15); }
        .badge-pending  { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .badge-approved { background: rgba(34,197,94,0.1);  color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .badge-rejected { background: rgba(239,68,68,0.1);  color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }

        /* ===== ACTION BAR ===== */
        .action-bar { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 0.85rem 1.25rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; position: relative; overflow: hidden; }
        .action-bar::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .action-bar-left { display: flex; align-items: center; gap: 0.85rem; flex: 1; min-width: 0; }
        .action-bar-right { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
        .action-search-wrap { position: relative; flex: 1; }
        .action-search-wrap input { width: 100%; padding: 0.5rem 1rem 0.5rem 2.35rem; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.85rem; color: var(--text-primary); background: #f8fafc; transition: all 0.2s; }
        .action-search-wrap input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; background: #fff; }
        .action-search-wrap input::placeholder { color: #94a3b8; }
        .action-search-wrap .ab-search-icon { position: absolute; left: 0.72rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.83rem; pointer-events: none; }
        .btn-outline-admin { background: var(--card-bg); color: var(--text-secondary); border: 1px solid #e2e8f0; padding: 0.5rem 1rem; font-size: 0.82rem; font-weight: 600; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; cursor: pointer; }
        .btn-outline-admin:hover { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.03); }
        .btn-outline-admin.filter-active { border-color: var(--gold); color: var(--gold-dark); background: rgba(212,175,55,0.04); }
        .filter-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 5px; background: var(--blue); color: #fff; border-radius: 10px; font-size: 0.7rem; font-weight: 700; }
        .sort-select { padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.82rem; font-weight: 500; color: var(--text-primary); background: #f8fafc; cursor: pointer; transition: all 0.2s; }
        .sort-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }

        /* ===== FILTER SIDEBAR ===== */
        .sf-sidebar { position: fixed; top: 0; right: 0; width: 100%; height: 100%; z-index: 10050; pointer-events: none; }
        .sf-sidebar.active { pointer-events: all; }
        .sf-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.4); opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .sf-sidebar.active .sf-overlay { opacity: 1; pointer-events: all; }
        .sf-content { position: absolute; top: 0; right: 0; width: 480px; max-width: 92vw; height: 100%; background: #fff; border-left: 1px solid rgba(37,99,235,0.15); box-shadow: -8px 0 32px rgba(15,23,42,0.1); transform: translateX(100%); transition: transform 0.25s ease; display: flex; flex-direction: column; overflow: hidden; }
        .sf-sidebar.active .sf-content { transform: translateX(0); }
        .sf-header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; padding: 1.25rem 1.5rem; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; flex-shrink: 0; }
        .sf-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); }
        .sf-header h4 { font-weight: 700; font-size: 1.05rem; display: flex; align-items: center; gap: 0.6rem; margin: 0; }
        .sf-header h4 i { color: var(--gold); }
        .sf-header-right { display: flex; align-items: center; gap: 0.6rem; }
        .sf-active-pill { display: none; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; background: rgba(212,175,55,0.15); color: var(--gold); border: 1px solid rgba(212,175,55,0.25); border-radius: 10px; font-size: 0.72rem; font-weight: 700; }
        .sf-active-pill.show { display: inline-flex; }
        .btn-close-sf { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff; width: 34px; height: 34px; border-radius: 4px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; font-size: 0.9rem; flex-shrink: 0; }
        .btn-close-sf:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); }
        .sf-results-bar { background: rgba(37,99,235,0.04); border-bottom: 1px solid rgba(37,99,235,0.1); padding: 0.7rem 1.5rem; display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
        .sf-results-bar i { color: var(--blue); font-size: 0.95rem; }
        .sf-results-num { font-size: 1.1rem; font-weight: 800; color: var(--blue); }
        .sf-results-label { font-size: 0.78rem; color: var(--text-secondary); }
        .sf-body { flex: 1; overflow-y: auto; padding: 1.1rem; background: #f8fafc; }
        .sf-body::-webkit-scrollbar { width: 4px; }
        .sf-body::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.3); border-radius: 4px; }
        .sf-section { background: #fff; border-radius: 4px; padding: 1rem 1.1rem; margin-bottom: 0.75rem; border: 1px solid #e2e8f0; }
        .sf-section:last-child { margin-bottom: 0; }
        .sf-section-title { font-weight: 700; font-size: 0.73rem; color: var(--text-primary); margin-bottom: 0.8rem; padding-bottom: 0.5rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 0.45rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .sf-section-title i { color: var(--gold); font-size: 0.85rem; }
        .sf-search-wrap { position: relative; }
        .sf-search-wrap input { width: 100%; padding: 0.6rem 0.85rem 0.6rem 2.35rem; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.85rem; color: var(--text-primary); transition: all 0.2s; }
        .sf-search-wrap input::placeholder { color: #94a3b8; }
        .sf-search-wrap input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .sf-search-wrap > i { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; pointer-events: none; }
        .price-slider-container { position: relative; height: 40px; margin-bottom: 1rem; }
        .price-slider-track { position: absolute; top: 50%; left: 0; right: 0; height: 6px; background: #e2e8f0; border-radius: 3px; transform: translateY(-50%); }
        .price-slider-range { position: absolute; height: 100%; background: linear-gradient(90deg, var(--gold-dark), var(--gold)); border-radius: 3px; }
        .price-range-slider { position: absolute; width: 100%; height: 6px; top: 50%; transform: translateY(-50%); background: transparent; pointer-events: none; -webkit-appearance: none; appearance: none; }
        .price-range-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 20px; height: 20px; border-radius: 50%; background: #fff; border: 3px solid var(--gold); cursor: pointer; pointer-events: all; box-shadow: 0 2px 6px rgba(0,0,0,0.15); transition: box-shadow 0.2s; }
        .price-range-slider::-webkit-slider-thumb:hover { box-shadow: 0 3px 10px rgba(212,175,55,0.3); }
        .price-range-inputs { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0.6rem; align-items: center; }
        .price-input { position: relative; }
        .price-input input { width: 100%; padding: 0.55rem 0.65rem 0.55rem 1.7rem; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.82rem; font-weight: 600; color: var(--text-primary); transition: all 0.2s; }
        .price-input input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .price-input .currency-sym { position: absolute; left: 0.5rem; top: 50%; transform: translateY(-50%); color: var(--gold-dark); font-weight: 700; font-size: 0.76rem; pointer-events: none; }
        .range-divider { color: #94a3b8; font-weight: 600; text-align: center; font-size: 0.9rem; }
        .filter-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .filter-chip { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.42rem 0.8rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 2px; cursor: pointer; transition: all 0.2s; font-size: 0.795rem; font-weight: 500; color: var(--text-primary); user-select: none; }
        .filter-chip:hover { background: #f8fafc; border-color: var(--gold); }
        .filter-chip.active { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; border-color: var(--gold-dark); font-weight: 600; }
        .filter-chip input[type="checkbox"] { width: 14px; height: 14px; cursor: pointer; accent-color: var(--gold); }
        .sf-select { width: 100%; padding: 0.55rem 0.8rem; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.84rem; font-weight: 500; color: var(--text-primary); transition: all 0.2s; }
        .sf-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .date-range-inputs { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0.55rem; align-items: center; }
        .date-range-inputs input { width: 100%; padding: 0.52rem 0.65rem; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.8rem; font-weight: 500; color: var(--text-primary); min-width: 0; transition: all 0.2s; }
        .date-range-inputs input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .quick-filters { display: flex; gap: 0.4rem; margin-top: 0.55rem; flex-wrap: wrap; }
        .quick-filter-btn { padding: 0.32rem 0.72rem; border: 1px solid #e2e8f0; background: #fff; border-radius: 2px; font-size: 0.73rem; font-weight: 500; cursor: pointer; transition: all 0.2s; color: var(--text-primary); }
        .quick-filter-btn:hover { border-color: var(--gold); background: #fffbeb; }
        .quick-filter-btn.active { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; border-color: var(--gold-dark); font-weight: 600; }
        .sf-footer { padding: 1rem 1.1rem; background: #fff; border-top: 1px solid #e2e8f0; display: flex; gap: 0.55rem; flex-shrink: 0; }
        .sf-footer .btn { flex: 1; padding: 0.62rem 1rem; font-weight: 600; border-radius: 4px; font-size: 0.83rem; transition: all 0.2s; cursor: pointer; border: none; }
        .sf-footer .btn-reset { background: #fff; border: 1px solid #e2e8f0 !important; color: var(--text-secondary); }
        .sf-footer .btn-reset:hover { border-color: rgba(239,68,68,0.3) !important; color: #dc2626; background: rgba(239,68,68,0.03); }
        .sf-footer .btn-apply { background: linear-gradient(135deg, var(--blue-dark, #1e40af), var(--blue)); color: #fff; }
        .sf-footer .btn-apply:hover { box-shadow: 0 4px 12px rgba(37,99,235,0.25); }
        .sf-no-results { text-align: center; padding: 3rem 2rem; color: var(--text-secondary); display: none; }
        .sf-no-results i { font-size: 2.5rem; opacity: 0.25; display: block; margin-bottom: 0.75rem; }
        .sf-no-results p { margin: 0; font-size: 0.88rem; }

        /* ===== CONTENT AREA ===== */
        .tab-content { padding: 1.5rem; }
        .sales-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }

        /* ===== SALE CARD (consistent with property.php card style) ===== */
        .sale-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; transition: all 0.3s ease; height: 100%; display: flex; flex-direction: column; position: relative; }
        .sale-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); opacity: 0; transition: opacity 0.3s ease; z-index: 5; }
        .sale-card:hover { border-color: rgba(37,99,235,0.25); box-shadow: 0 8px 32px rgba(37,99,235,0.08); transform: translateY(-4px); }
        .sale-card:hover::before { opacity: 1; }

        .card-img-wrap { position: relative; height: 180px; background: #f1f5f9; overflow: hidden; }
        .card-img-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .sale-card:hover .card-img-wrap img { transform: scale(1.05); }
        .card-img-wrap .img-overlay { position: absolute; bottom: 0; left: 0; right: 0; height: 60%; background: linear-gradient(to top, rgba(0,0,0,0.65) 0%, transparent 100%); pointer-events: none; }

        /* Badges on image */
        .card-img-wrap .type-badge { position: absolute; bottom: 12px; left: 14px; padding: 0.2rem 0.6rem; border-radius: 2px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 3; background: rgba(0,0,0,0.7); color: #e2e8f0; backdrop-filter: blur(4px); display: inline-flex; align-items: center; gap: 0.3rem; }
        .card-img-wrap .status-badge { position: absolute; top: 12px; right: 12px; display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.65rem; border-radius: 2px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 3; }
        .status-badge.pending  { background: rgba(245,158,11,0.9); color: #fff; }
        .status-badge.approved { background: rgba(34,197,94,0.9);  color: #fff; }
        .status-badge.rejected { background: rgba(239,68,68,0.9);  color: #fff; }

        .card-img-wrap .price-overlay { position: absolute; bottom: 12px; right: 14px; z-index: 3; }
        .card-img-wrap .price-overlay .price { font-size: 1.3rem; font-weight: 800; background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light, #e8c558) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.5)); }

        /* Card Body */
        .sale-card .card-body-content { padding: 1rem 1.25rem; flex: 1; display: flex; flex-direction: column; position: relative; z-index: 2; }
        .sale-card .prop-address { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
        .sale-card .prop-location { font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-bottom: 0.75rem; }
        .sale-card .prop-location i { color: var(--blue); font-size: 0.75rem; }

        /* Meta Row (sale-specific) */
        .sale-meta-row { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
        .sale-meta-item { display: inline-flex; align-items: center; gap: 0.3rem; background: #f8fafc; padding: 0.2rem 0.55rem; border-radius: 2px; border: 1px solid #e2e8f0; font-size: 0.75rem; font-weight: 500; color: var(--text-secondary); }
        .sale-meta-item i { color: #94a3b8; font-size: 0.7rem; }
        .sale-meta-item.agent-meta i { color: var(--blue); }
        .sale-meta-item.date-meta i { color: var(--gold-dark); }

        /* Card footer */
        .sale-card .card-footer-section { margin-top: auto; padding-top: 0.75rem; border-top: 1px solid #e2e8f0; }
        .sale-card .posted-by { font-size: 0.75rem; color: #94a3b8; margin-bottom: 0.6rem; text-align: center; display: flex; align-items: center; justify-content: center; gap: 0.3rem; }
        .sale-card .posted-by i { color: #cbd5e1; }
        .sale-card .btn-manage { display: flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%; background: linear-gradient(135deg, var(--blue-dark, #1e40af) 0%, var(--blue) 100%); color: #fff; border: none; padding: 0.6rem; font-size: 0.8rem; font-weight: 700; border-radius: 4px; cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(37,99,235,0.2); }
        .sale-card .btn-manage:hover { box-shadow: 0 4px 16px rgba(37,99,235,0.3); transform: translateY(-1px); }

        /* Pending actions row */
        .sale-card .pending-actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
        .pending-actions .btn-approve-sm, .pending-actions .btn-reject-sm { flex: 1; padding: 0.45rem; font-size: 0.75rem; font-weight: 700; border: none; border-radius: 3px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 0.3rem; text-transform: uppercase; letter-spacing: 0.03em; }
        .btn-approve-sm { background: rgba(34,197,94,0.12); color: #16a34a; border: 1px solid rgba(34,197,94,0.2) !important; }
        .btn-approve-sm:hover { background: #22c55e; color: #fff; }
        .btn-reject-sm { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.18) !important; }
        .btn-reject-sm:hover { background: #ef4444; color: #fff; }

        /* ===== NEEDS FINALIZATION HIGHLIGHT ===== */
        .sale-card.needs-finalization { border-color: rgba(212,175,55,0.35); box-shadow: 0 0 0 1px rgba(212,175,55,0.12); }
        .sale-card.needs-finalization::before { opacity: 1; background: linear-gradient(90deg, var(--gold), var(--gold-dark), var(--gold)); }
        .sale-card.needs-finalization::after { content: ''; position: absolute; inset: 0; border-radius: 4px; box-shadow: 0 0 12px rgba(212,175,55,0.1); pointer-events: none; z-index: 0; animation: finalize-pulse 2.5s ease-in-out infinite; }
        @keyframes finalize-pulse { 0%, 100% { box-shadow: 0 0 8px rgba(212,175,55,0.08); } 50% { box-shadow: 0 0 18px rgba(212,175,55,0.18); } }
        .finalize-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.55rem; border-radius: 2px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(180,130,10,0.88); color: #fff; border: 1px solid rgba(212,175,55,0.4); position: absolute; top: 12px; left: 12px; z-index: 4; backdrop-filter: blur(4px); }
        .finalize-badge i { font-size: 0.6rem; }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 4rem 2rem; background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; }
        .empty-state i { font-size: 3rem; color: var(--text-secondary); opacity: 0.3; margin-bottom: 0.75rem; display: block; }
        .empty-state h4 { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; }
        .empty-state p { color: var(--text-secondary); margin: 0; }

        /* ===== (alerts converted to toast) ===== */

        /* ===== MODAL OVERLAY & CONTAINER ===== */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; z-index: 1050; opacity: 0; transition: opacity 0.25s ease; backdrop-filter: blur(2px); }
        .modal-overlay.show { display: flex; opacity: 1; align-items: center; justify-content: center; }
        .modal-container { background: var(--card-bg); border-radius: 6px; box-shadow: 0 20px 60px rgba(0,0,0,0.18); max-width: 820px; width: 92%; max-height: 92vh; overflow-y: auto; transform: scale(0.96) translateY(8px); opacity: 0; transition: all 0.25s cubic-bezier(0.16,1,0.3,1); border: 1px solid rgba(37,99,235,0.12); }
        .modal-large { max-width: 1100px; width: 96%; }
        .modal-overlay.show .modal-container { opacity: 1; transform: scale(1) translateY(0); }

        /* Scrollbar styling for modal */
        .modal-container::-webkit-scrollbar { width: 5px; }
        .modal-container::-webkit-scrollbar-track { background: #f1f1f1; }
        .modal-container::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.4); border-radius: 4px; }

        .modal-admin-header { background: var(--card-bg); padding: 1.25rem 1.75rem; border-bottom: 1px solid rgba(37,99,235,0.1); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 10; }
        .modal-admin-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent 0%, var(--gold) 30%, var(--blue) 70%, transparent 100%); }
        .modal-admin-header h2 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .modal-admin-header h2 i { color: var(--gold-dark); }
        .modal-header-meta { display: flex; align-items: center; gap: 0.75rem; }
        .modal-vid-badge { font-size: 0.7rem; font-weight: 700; background: rgba(212,175,55,0.1); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); padding: 0.2rem 0.6rem; border-radius: 2px; letter-spacing: 0.5px; }
        .modal-close-btn { background: none; border: 1px solid rgba(37,99,235,0.12); width: 32px; height: 32px; border-radius: 4px; font-size: 1.1rem; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
        .modal-close-btn:hover { background: rgba(239,68,68,0.08); color: #ef4444; border-color: rgba(239,68,68,0.25); }
        .modal-body { padding: 0; }
        .modal-footer { padding: 1rem 1.75rem; background: rgba(37,99,235,0.02); border-top: 1px solid rgba(37,99,235,0.08); display: flex; gap: 0.6rem; justify-content: flex-end; align-items: center; }

        /* ===== SVD: HERO BANNER ===== */
        .svd-hero { position: relative; height: 260px; overflow: hidden; background: linear-gradient(135deg, #1a1a2e, #16213e); }
        .svd-hero-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
        .svd-hero:hover .svd-hero-img { transform: scale(1.02); }
        .svd-hero-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.65) 100%); }
        .svd-hero-no-img { width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: rgba(255,255,255,0.2); gap: 0.5rem; }
        .svd-hero-no-img i { font-size: 3.5rem; }
        .svd-hero-no-img span { font-size: 0.8rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; }
        .svd-hero-content { position: absolute; bottom: 0; left: 0; right: 0; padding: 1rem 1.5rem; z-index: 2; }
        .svd-hero-address { font-size: 1.15rem; font-weight: 800; color: #fff; text-shadow: 0 1px 4px rgba(0,0,0,0.4); margin-bottom: 0.2rem; line-height: 1.3; }
        .svd-hero-city { font-size: 0.8rem; color: rgba(255,255,255,0.75); display: flex; align-items: center; gap: 0.3rem; }
        .svd-hero-top { position: absolute; top: 0.85rem; left: 1rem; right: 1rem; display: flex; justify-content: space-between; align-items: flex-start; z-index: 2; }
        .svd-type-badge { background: rgba(255,255,255,0.92); color: var(--text-primary); padding: 0.28rem 0.65rem; border-radius: 3px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.5); }
        .svd-status-hero { padding: 0.28rem 0.75rem; border-radius: 3px; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .svd-status-hero.pending  { background: rgba(245,158,11,0.9); color: #fff; }
        .svd-status-hero.approved { background: rgba(34,197,94,0.9);  color: #fff; }
        .svd-status-hero.rejected { background: rgba(239,68,68,0.9);  color: #fff; }
        /* gallery dots on hero */
        .svd-hero-dots { position: absolute; bottom: 3.5rem; right: 1.25rem; display: flex; gap: 0.35rem; z-index: 3; }
        .svd-hero-dot { width: 7px; height: 7px; border-radius: 50%; border: none; background: rgba(255,255,255,0.4); cursor: pointer; transition: all 0.15s; padding: 0; }
        .svd-hero-dot.active { background: var(--gold); transform: scale(1.3); }
        .svd-gallery-prev, .svd-gallery-next { position: absolute; top: 50%; transform: translateY(-50%); z-index: 3; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: #fff; width: 34px; height: 34px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; transition: all 0.15s; backdrop-filter: blur(4px); }
        .svd-gallery-prev { left: 0.75rem; }
        .svd-gallery-next { right: 0.75rem; }
        .svd-gallery-prev:hover, .svd-gallery-next:hover { background: rgba(212,175,55,0.8); border-color: var(--gold); }
        .svd-gallery-prev:disabled, .svd-gallery-next:disabled { opacity: 0.3; cursor: not-allowed; }
        .svd-gallery-counter { position: absolute; top: 0.85rem; left: 50%; transform: translateX(-50%); z-index: 3; background: rgba(0,0,0,0.45); color: rgba(255,255,255,0.9); font-size: 0.7rem; font-weight: 600; padding: 0.2rem 0.55rem; border-radius: 10px; letter-spacing: 0.3px; backdrop-filter: blur(4px); display: none; }

        /* ===== SVD: STAT STRIP ===== */
        .svd-stat-strip { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1px solid rgba(37,99,235,0.08); }
        .svd-stat { padding: 1rem 1.25rem; text-align: center; position: relative; border-right: 1px solid rgba(37,99,235,0.06); transition: background 0.15s; }
        .svd-stat:last-child { border-right: none; }
        .svd-stat:hover { background: rgba(212,175,55,0.03); }
        .svd-stat-label { font-size: 0.62rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.3rem; }
        .svd-stat-value { font-size: 0.95rem; font-weight: 800; color: var(--text-primary); }
        .svd-stat-value.gold  { color: var(--gold-dark); font-size: 1.05rem; }
        .svd-stat-value.green { color: #16a34a; }
        .svd-stat-value.red   { color: #dc2626; }
        .svd-stat-sub { font-size: 0.65rem; color: var(--text-secondary); margin-top: 0.1rem; }
        .svd-variance { font-size: 0.65rem; font-weight: 700; padding: 0.1rem 0.4rem; border-radius: 2px; margin-top: 0.2rem; display: inline-block; }
        .svd-variance.up   { background: rgba(34,197,94,0.1); color: #16a34a; }
        .svd-variance.down { background: rgba(239,68,68,0.1); color: #dc2626; }
        .svd-variance.flat { background: rgba(107,114,128,0.1); color: #6b7280; }

        /* ===== SVD: BODY SECTIONS ===== */
        .svd-body { padding: 1.5rem; }
        .svd-section { margin-bottom: 1.5rem; }
        .svd-section:last-child { margin-bottom: 0; }
        .svd-section-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--gold-dark); margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid rgba(212,175,55,0.15); position: relative; }
        .svd-section-title::before { content: ''; position: absolute; bottom: -1px; left: 0; width: 32px; height: 2px; background: var(--gold); border-radius: 1px; }
        .svd-section-title i { font-size: 0.85rem; }

        /* Two-column panel */
        .svd-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .svd-panel { background: #fafbfe; border: 1px solid rgba(37,99,235,0.07); border-radius: 5px; padding: 1rem 1.25rem; }
        .svd-panel-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem; }
        .svd-panel-title.buyer { color: var(--gold-dark); }
        .svd-panel-title.blue  { color: var(--blue); }
        .svd-panel-title.green { color: #16a34a; }
        .svd-row { display: flex; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.55rem; }
        .svd-row:last-child { margin-bottom: 0; }
        .svd-row-icon { width: 18px; font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.1rem; flex-shrink: 0; text-align: center; }
        .svd-row-label { font-size: 0.68rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; min-width: 68px; flex-shrink: 0; margin-top: 0.1rem; }
        .svd-row-value { font-size: 0.82rem; color: var(--text-primary); font-weight: 500; word-break: break-word; }
        .svd-row-value.strong { font-weight: 700; }
        .svd-email-link { color: var(--blue); text-decoration: none; }
        .svd-email-link:hover { text-decoration: underline; }

        /* Detail grid for property info */
        .svd-detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
        .svd-detail-cell { background: #fafbfe; border: 1px solid rgba(37,99,235,0.07); border-radius: 5px; padding: 0.75rem 1rem; }
        .svd-detail-cell .cell-label { font-size: 0.62rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.25rem; }
        .svd-detail-cell .cell-value { font-size: 0.88rem; color: var(--text-primary); font-weight: 600; }
        .svd-detail-cell .cell-value.gold  { color: var(--gold-dark); font-size: 1rem; }
        .svd-detail-cell .cell-value.muted { font-weight: 400; color: var(--text-secondary); }

        /* Status display inline */
        .svd-status-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
        .svd-status-pill.pending  { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.2); }
        .svd-status-pill.approved { background: rgba(34,197,94,0.1);  color: #16a34a; border: 1px solid rgba(34,197,94,0.2); }
        .svd-status-pill.rejected { background: rgba(239,68,68,0.1);  color: #dc2626; border: 1px solid rgba(239,68,68,0.2); }
        .svd-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
        .svd-dot.pending  { background: #d97706; }
        .svd-dot.approved { background: #16a34a; }
        .svd-dot.rejected { background: #dc2626; }

        /* Notes / rejection box */
        .svd-rejection-box { background: rgba(239,68,68,0.04); border: 1px solid rgba(239,68,68,0.12); border-left: 3px solid #ef4444; padding: 0.85rem 1.1rem; border-radius: 4px; }
        .svd-rejection-box .rej-title { font-size: 0.65rem; font-weight: 700; color: #ef4444; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.35rem; }
        .svd-rejection-box .rej-text { font-size: 0.85rem; color: #7f1d1d; line-height: 1.55; }
        .svd-notes-box { background: rgba(37,99,235,0.03); border: 1px solid rgba(37,99,235,0.1); border-left: 3px solid var(--blue); padding: 0.85rem 1.1rem; border-radius: 4px; }
        .svd-notes-box .notes-title { font-size: 0.65rem; font-weight: 700; color: var(--blue); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.35rem; }
        .svd-notes-box .notes-text { font-size: 0.85rem; color: var(--text-primary); line-height: 1.55; white-space: pre-wrap; }

        /* Commission panel */
        .svd-commission-panel { background: linear-gradient(135deg, rgba(34,197,94,0.05) 0%, rgba(16,163,74,0.03) 100%); border: 1px solid rgba(34,197,94,0.15); border-radius: 5px; padding: 1rem 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .svd-commission-panel .cp-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #16a34a; margin-bottom: 0.2rem; display: flex; align-items: center; gap: 0.35rem; }
        .svd-commission-panel .cp-value { font-size: 1.4rem; font-weight: 900; color: #16a34a; }
        .svd-commission-panel .cp-pct { font-size: 0.75rem; color: #16a34a; font-weight: 600; margin-top: 0.1rem; }
        .svd-commission-badge { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); color: #16a34a; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.7rem; font-weight: 700; white-space: nowrap; }

        /* Timeline */
        .svd-timeline { display: flex; flex-direction: column; gap: 0; }
        .svd-tl-item { display: flex; align-items: flex-start; gap: 0.85rem; padding: 0.65rem 0; position: relative; }
        .svd-tl-item:not(:last-child)::after { content: ''; position: absolute; left: 10px; top: 2rem; bottom: -0.65rem; width: 1px; background: rgba(37,99,235,0.12); }
        .svd-tl-dot { width: 21px; height: 21px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; flex-shrink: 0; z-index: 1; }
        .svd-tl-dot.gold  { background: rgba(212,175,55,0.15); color: var(--gold-dark); border: 1.5px solid rgba(212,175,55,0.35); }
        .svd-tl-dot.green { background: rgba(34,197,94,0.12); color: #16a34a; border: 1.5px solid rgba(34,197,94,0.3); }
        .svd-tl-dot.red   { background: rgba(239,68,68,0.1); color: #dc2626; border: 1.5px solid rgba(239,68,68,0.3); }
        .svd-tl-dot.gray  { background: rgba(107,114,128,0.1); color: #6b7280; border: 1.5px solid rgba(107,114,128,0.2); }
        .svd-tl-content .tl-event { font-size: 0.8rem; font-weight: 600; color: var(--text-primary); }
        .svd-tl-content .tl-time  { font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.1rem; }

        /* Documents */
        .svd-doc-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .svd-doc-item { display: flex; align-items: center; gap: 0.85rem; padding: 0.75rem 1rem; background: #fafbfe; border-radius: 5px; border: 1px solid rgba(37,99,235,0.07); transition: border-color 0.15s, background 0.15s; }
        .svd-doc-item:hover { border-color: rgba(212,175,55,0.25); background: rgba(212,175,55,0.02); }
        .svd-doc-icon-wrap { width: 40px; height: 40px; border-radius: 5px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
        .svd-doc-icon-wrap.pdf  { background: rgba(239,68,68,0.08);  color: #dc2626; border: 1px solid rgba(239,68,68,0.12); }
        .svd-doc-icon-wrap.img  { background: rgba(37,99,235,0.07);  color: var(--blue); border: 1px solid rgba(37,99,235,0.12); }
        .svd-doc-icon-wrap.word { background: rgba(37,99,235,0.08);  color: #1d4ed8; border: 1px solid rgba(37,99,235,0.15); }
        .svd-doc-icon-wrap.file { background: rgba(107,114,128,0.08); color: #6b7280; border: 1px solid rgba(107,114,128,0.12); }
        .svd-doc-info { flex: 1; min-width: 0; }
        .svd-doc-name { font-size: 0.83rem; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .svd-doc-meta { font-size: 0.68rem; color: var(--text-secondary); margin-top: 0.1rem; }
        .svd-doc-actions { display: flex; gap: 0.35rem; flex-shrink: 0; }
        .svd-btn-doc { padding: 0.3rem 0.6rem; font-size: 0.7rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 0.25rem; }
        .svd-btn-doc.preview { background: rgba(212,175,55,0.1); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); }
        .svd-btn-doc.preview:hover { background: var(--gold); color: var(--text-primary); }
        .svd-btn-doc.download { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .svd-btn-doc.download:hover { background: var(--blue); color: #fff; }

        /* Modal action buttons */
        .btn-modal { padding: 0.55rem 1.35rem; font-size: 0.83rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-modal:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
        .btn-modal-primary   { background: var(--gold); color: #ffffff; }
        .btn-modal-primary:hover   { background: var(--gold-dark); color: #ffffff; }
        .btn-modal-success   { background: #22c55e; color: #fff; }
        .btn-modal-success:hover   { background: #16a34a; }
        .btn-modal-danger    { background: #ef4444; color: #fff; }
        .btn-modal-danger:hover    { background: #dc2626; }
        .btn-modal-secondary { background: rgba(37,99,235,0.07); color: var(--text-secondary); border: 1px solid rgba(37,99,235,0.1); }
        .btn-modal-secondary:hover { background: rgba(37,99,235,0.14); color: var(--text-primary); }
        .btn-modal-blue { background: var(--blue); color: #fff; }
        .btn-modal-blue:hover { background: var(--blue-dark); }

        /* Status display (old classes kept for compat) */
        .status-display { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.75rem; border-radius: 3px; font-size: 0.8rem; font-weight: 600; }
        .status-display.pending  { background: rgba(245,158,11,0.1); color: #d97706; }
        .status-display.approved { background: rgba(34,197,94,0.1);  color: #16a34a; }
        .status-display.rejected { background: rgba(239,68,68,0.1);  color: #dc2626; }

        /* Property gallery (old classes kept for non-details modals) */
        .property-gallery { position: relative; width: 100%; height: 280px; overflow: hidden; border-radius: 4px; margin-bottom: 0.75rem; }
        .gallery-item { position: absolute; inset: 0; opacity: 0; display: none; transition: opacity 0.3s; }
        .gallery-item.active { opacity: 1; display: block; }
        .gallery-image { width: 100%; height: 100%; object-fit: cover; }
        .gallery-navigation { display: flex; align-items: center; justify-content: center; gap: 0.75rem; }
        .gallery-nav-btn { background: var(--gold); color: var(--text-primary); border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; font-size: 0.9rem; }
        .gallery-nav-btn:hover:not(:disabled) { background: var(--gold-dark); }
        .gallery-nav-btn:disabled { background: #dee2e6; color: #adb5bd; cursor: not-allowed; }
        .gallery-indicators { display: flex; gap: 0.35rem; }
        .gallery-indicator { width: 10px; height: 10px; border-radius: 50%; border: none; background: #dee2e6; cursor: pointer; transition: all 0.15s; }
        .gallery-indicator.active { background: var(--gold); transform: scale(1.2); }

        /* Admin / commission boxes (old compat) */
        .admin-notes-box { background: rgba(239,68,68,0.04); border: 1px solid rgba(239,68,68,0.1); border-left: 3px solid #ef4444; padding: 0.75rem 1rem; border-radius: 3px; margin-top: 0.5rem; }
        .admin-notes-box .notes-label { font-size: 0.65rem; font-weight: 700; color: #ef4444; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .admin-notes-box .notes-text { font-size: 0.85rem; color: var(--text-primary); line-height: 1.5; }
        .commission-box { background: rgba(34,197,94,0.04); border: 1px solid rgba(34,197,94,0.1); border-left: 3px solid #22c55e; padding: 0.75rem 1rem; border-radius: 3px; margin-top: 0.5rem; }
        .commission-box .comm-label { font-size: 0.65rem; font-weight: 700; color: #16a34a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .commission-box .comm-value { font-size: 1rem; color: #16a34a; font-weight: 800; }

        @media (max-width: 768px) {
            .svd-stat-strip { grid-template-columns: repeat(2,1fr); }
            .svd-two-col { grid-template-columns: 1fr; }
            .svd-hero { height: 200px; }
            .svd-detail-grid { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 480px) {
            .svd-stat-strip { grid-template-columns: repeat(2,1fr); }
            .svd-detail-grid { grid-template-columns: 1fr; }
        }

        /* ===== PROCESSING OVERLAY ===== */
        .processing-overlay {
            position: fixed; inset: 0;
            display: none; align-items: center; justify-content: center;
            background: rgba(15,23,42,0.45);
            backdrop-filter: blur(6px);
            z-index: 2000;
        }
        .processing-overlay.show { display: flex; }

        .processing-card {
            background: var(--card-bg, #ffffff);
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            padding: 0;
            width: 380px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(37,99,235,0.08), 0 24px 64px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
            animation: pc-pop .3s cubic-bezier(.34,1.56,.64,1) forwards;
        }
        @keyframes pc-pop { from { opacity:0; transform: scale(.92) translateY(14px); } to { opacity:1; transform: scale(1) translateY(0); } }

        /* Top gradient bar — matches property.php page-header / action-bar */
        .processing-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold, #d4af37), var(--blue, #2563eb), transparent);
        }

        /* Shimmer sweep across card */
        .processing-card::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,0.04), transparent);
            animation: pc-sweep 2.2s ease-in-out infinite;
            pointer-events: none;
            z-index: 0;
        }
        @keyframes pc-sweep { 0% { left: -100%; } 100% { left: 100%; } }

        .pc-header {
            position: relative;
            z-index: 1;
            padding: 2rem 2rem 0;
        }

        .pc-ring-wrap {
            position: relative;
            width: 72px;
            height: 72px;
            margin: 0 auto 1.25rem;
        }
        .pc-ring {
            position: absolute; inset: 0;
            border-radius: 50%;
            border: 2px solid transparent;
            border-top-color: var(--gold, #d4af37);
            border-right-color: rgba(212,175,55,0.2);
            animation: pc-spin 1s linear infinite;
        }
        .pc-ring-inner {
            position: absolute; inset: 9px;
            border-radius: 50%;
            border: 2px solid transparent;
            border-bottom-color: var(--blue, #2563eb);
            border-left-color: rgba(37,99,235,0.15);
            animation: pc-spin-rev .75s linear infinite;
        }
        @keyframes pc-spin { to { transform: rotate(360deg); } }
        @keyframes pc-spin-rev { to { transform: rotate(-360deg); } }

        .pc-icon-center {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.35rem;
            color: var(--gold-dark, #b8941f);
        }

        .pc-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.2rem;
        }
        .pc-subtitle {
            font-size: 0.78rem;
            color: var(--text-secondary, #64748b);
            margin-bottom: 0;
        }

        /* Steps section */
        .pc-steps-wrap {
            position: relative;
            z-index: 1;
            padding: 1.25rem 1.75rem 1.75rem;
        }
        .pc-steps {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            text-align: left;
        }
        .pc-step {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-size: 0.78rem;
            font-weight: 500;
            color: #9ca3af;
            padding: 0.5rem 0.7rem;
            border-radius: 4px;
            border: 1px solid transparent;
            transition: all .3s ease;
        }
        .pc-step.active {
            color: var(--text-primary, #0f172a);
            background: rgba(212,175,55,0.06);
            border-color: rgba(212,175,55,0.15);
        }
        .pc-step.done {
            color: #16a34a;
            background: rgba(22,163,74,0.04);
            border-color: rgba(22,163,74,0.1);
        }
        .pc-step-dot {
            width: 24px; height: 24px;
            border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.65rem;
            flex-shrink: 0;
            background: #f3f4f6;
            color: #9ca3af;
            border: 1px solid #e2e8f0;
            transition: all .3s;
        }
        .pc-step.active .pc-step-dot {
            background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.15));
            color: var(--gold-dark, #b8941f);
            border-color: rgba(212,175,55,0.3);
            animation: pc-pulse .8s ease-in-out infinite alternate;
        }
        @keyframes pc-pulse { from { box-shadow: 0 0 0 0 rgba(212,175,55,0.25); } to { box-shadow: 0 0 0 4px rgba(212,175,55,0); } }
        .pc-step.done .pc-step-dot {
            background: rgba(22,163,74,0.1);
            color: #16a34a;
            border-color: rgba(22,163,74,0.25);
        }

        /* Email step envelope animation */
        .pc-step.active .pc-step-dot .bi-envelope-paper {
            animation: pc-envelope 1.2s ease-in-out infinite;
        }
        @keyframes pc-envelope {
            0%   { transform: translateY(0) scale(1); }
            30%  { transform: translateY(-3px) scale(1.15); }
            50%  { transform: translateY(-1px) scale(1.05); }
            100% { transform: translateY(0) scale(1); }
        }

        /* Progress bar at bottom of processing card */
        .pc-progress {
            height: 3px;
            background: #f1f5f9;
            position: relative;
            overflow: hidden;
        }
        .pc-progress-bar {
            position: absolute;
            top: 0; left: 0;
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--gold, #d4af37), var(--blue, #2563eb));
            border-radius: 0 2px 2px 0;
            transition: width 0.5s ease;
        }

        /* ===== TOAST NOTIFICATIONS ===== */
        #toastContainer {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            pointer-events: none;
        }
        .app-toast {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            background: #ffffff;
            border-radius: 12px;
            padding: 0.9rem 1.1rem;
            min-width: 300px;
            max-width: 380px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.16), 0 0 0 1px rgba(0,0,0,0.06);
            pointer-events: all;
            position: relative;
            overflow: hidden;
            animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;
        }
        @keyframes toast-in { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }
        .app-toast::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
        }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast.toast-warning::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-money::before   { background: linear-gradient(180deg, #22c55e, #16a34a); }
        .app-toast-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }
        .toast-warning .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-money   .app-toast-icon { background: rgba(34,197,94,0.12);  color: #22c55e; }
        .app-toast-body { flex: 1; min-width: 0; }
        .app-toast-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.2rem;
        }
        .app-toast-msg {
            font-size: 0.78rem;
            color: #6b7280;
            line-height: 1.4;
            word-break: break-word;
        }
        .app-toast-close {
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: 0.8rem;
            padding: 0; line-height: 1;
            flex-shrink: 0;
            transition: color .2s;
        }
        .app-toast-close:hover { color: #374151; }
        .app-toast-progress {
            position: absolute;
            bottom: 0; left: 0;
            height: 2px;
            border-radius: 0 0 0 12px;
        }
        .toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        .toast-warning .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-money   .app-toast-progress { background: linear-gradient(90deg, #22c55e, #16a34a); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .page-header h1 { font-size: 1.3rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .kpi-card { padding: 1rem; }
            .sale-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
            .sale-tabs .nav-link { white-space: nowrap; padding: 0.75rem 0.85rem; font-size: 0.8rem; }
            .sales-grid { grid-template-columns: 1fr; }
            .modal-container { width: 98%; }
        }
        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .sale-tabs .nav-link { padding: 0.6rem 0.7rem; font-size: 0.75rem; }
        }

        /* ===== COMMISSION MANAGEMENT SECTION (cm-) ===== */
        .cm-section {
            margin-top: 2.5rem;
            margin-bottom: 2rem;
        }
        .cm-section-header {
            background: var(--card-bg);
            border: 1px solid rgba(34,197,94,0.15);
            border-radius: 4px;
            padding: 1.5rem 2rem;
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
        }
        .cm-section-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(ellipse at top right, rgba(34,197,94,0.04) 0%, transparent 50%),
                        radial-gradient(ellipse at bottom left, rgba(22,163,74,0.03) 0%, transparent 50%);
            pointer-events: none;
        }
        .cm-section-header::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #22c55e, #16a34a, transparent);
        }
        .cm-header-inner {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .cm-header-left h2 {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .cm-header-left h2 i { color: #22c55e; }
        .cm-header-left .cm-subtitle {
            font-size: 0.88rem;
            color: var(--text-secondary);
        }
        .cm-kpi-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .cm-kpi {
            background: rgba(34,197,94,0.06);
            border: 1px solid rgba(34,197,94,0.12);
            border-radius: 4px;
            padding: 0.6rem 1rem;
            text-align: center;
            min-width: 110px;
        }
        .cm-kpi-label {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }
        .cm-kpi-value {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-primary);
        }
        .cm-kpi-value.money { color: #16a34a; }

        /* Commission Table */
        .cm-table-wrap {
            background: var(--card-bg);
            border: 1px solid rgba(34,197,94,0.12);
            border-radius: 4px;
            overflow: hidden;
        }
        .cm-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .cm-table thead th {
            background: rgba(34,197,94,0.05);
            padding: 0.85rem 1rem;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            border-bottom: 1px solid rgba(34,197,94,0.12);
            white-space: nowrap;
        }
        .cm-table tbody tr {
            transition: background 0.15s ease;
        }
        .cm-table tbody tr:hover {
            background: rgba(34,197,94,0.03);
        }
        .cm-table tbody td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .cm-table tbody tr:last-child td {
            border-bottom: none;
        }
        .cm-agent-cell {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .cm-agent-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: rgba(34,197,94,0.1);
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .cm-agent-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.82rem;
        }
        .cm-prop-cell {
            max-width: 220px;
        }
        .cm-prop-addr {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.82rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cm-prop-city {
            font-size: 0.72rem;
            color: var(--text-secondary);
        }
        .cm-amount {
            font-weight: 700;
            color: #16a34a;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        .cm-rate {
            font-size: 0.72rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .cm-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .cm-status-badge.calculated {
            background: rgba(37,99,235,0.08);
            color: #2563eb;
        }
        .cm-status-badge.processing {
            background: rgba(245,158,11,0.1);
            color: #d97706;
        }
        .cm-status-badge.paid {
            background: rgba(34,197,94,0.1);
            color: #16a34a;
        }
        .cm-btn-pay {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.85rem;
            border: none;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #fff;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .cm-btn-pay:hover {
            background: linear-gradient(135deg, #16a34a, #15803d);
            box-shadow: 0 2px 8px rgba(34,197,94,0.3);
            transform: translateY(-1px);
        }
        .cm-btn-pay:active {
            transform: translateY(0);
        }
        .cm-btn-view {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.35rem 0.7rem;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            background: transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .cm-btn-view:hover {
            border-color: var(--blue);
            color: var(--blue);
            background: rgba(37,99,235,0.04);
        }
        .cm-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .cm-empty-state {
            padding: 3rem 2rem;
            text-align: center;
            color: var(--text-secondary);
        }
        .cm-empty-state i {
            font-size: 2.5rem;
            color: #22c55e;
            opacity: 0.4;
            margin-bottom: 0.75rem;
        }
        .cm-empty-state h4 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.3rem;
        }
        .cm-empty-state p {
            font-size: 0.82rem;
        }
        .cm-date {
            font-size: 0.8rem;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        /* Commission table search bar */
        .cm-search-bar {
            position: relative;
            margin-bottom: 0.85rem;
            display: flex;
            align-items: center;
        }
        .cm-search-icon {
            position: absolute;
            left: 0.85rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
            pointer-events: none;
        }
        .cm-search-bar input {
            width: 100%;
            padding: 0.6rem 2.5rem 0.6rem 2.25rem;
            border: 1px solid rgba(34,197,94,0.18);
            border-radius: 4px;
            font-size: 0.83rem;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            background: var(--card-bg);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .cm-search-bar input:focus {
            outline: none;
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34,197,94,0.08);
        }
        .cm-search-bar input::placeholder { color: rgba(0,0,0,0.28); }
        .cm-search-clear {
            position: absolute;
            right: 0.75rem;
            background: rgba(0,0,0,0.06);
            border: none;
            border-radius: 50%;
            width: 20px; height: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem;
            color: var(--text-secondary);
            cursor: pointer;
            line-height: 1;
            transition: background 0.15s;
        }
        .cm-search-clear:hover { background: rgba(220,38,38,0.1); color: #dc2626; }
        .cm-no-results {
            padding: 2rem;
            text-align: center;
            font-size: 0.83rem;
            color: var(--text-secondary);
        }
        .cm-no-results i { font-size: 1.5rem; display: block; margin-bottom: 0.4rem; opacity: 0.4; }

        /* Commission table toggle */
        .cm-toggle-row {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .cm-toggle-btn {
            padding: 0.5rem 1rem;
            border: 1px solid rgba(34,197,94,0.2);
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            background: transparent;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .cm-toggle-btn.active {
            background: rgba(34,197,94,0.08);
            border-color: #22c55e;
            color: #16a34a;
        }
        .cm-toggle-btn:hover:not(.active) {
            border-color: rgba(34,197,94,0.4);
            color: var(--text-primary);
        }
        .cm-toggle-count {
            background: rgba(34,197,94,0.12);
            color: #16a34a;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 0.1rem 0.45rem;
            border-radius: 10px;
        }

        /* Responsive commission table */
        @media (max-width: 768px) {
            .cm-section-header { padding: 1rem; }
            .cm-header-inner { flex-direction: column; align-items: flex-start; }
            .cm-kpi-row { width: 100%; }
            .cm-kpi { flex: 1; min-width: 0; }
            .cm-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .cm-table { min-width: 700px; }
        }
        @media (max-width: 576px) {
            .cm-header-left h2 { font-size: 1.1rem; }
            .cm-kpi { padding: 0.4rem 0.6rem; }
            .cm-kpi-value { font-size: 0.95rem; }
        }

        /* ===== FINALIZE SALE MODAL (fsm-) ===== */
        .fsm-overlay .modal-dialog { max-width: 740px; }
        .fsm-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
        .fsm-shell.modal-content {
            background: #ffffff !important;
            border: 1px solid rgba(37,99,235,0.1) !important;
            border-radius: 8px !important;
            box-shadow: 0 28px 70px rgba(0,0,0,0.2) !important;
            overflow: hidden;
            padding: 0 !important;
            font-family: 'Inter', sans-serif;
        }
        .fsm-header {
            position: relative;
            padding: 1.5rem 1.75rem 1.25rem;
            border-bottom: 1px solid rgba(37,99,235,0.08);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .fsm-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        .fsm-header-icon {
            width: 46px; height: 46px;
            background: linear-gradient(135deg, rgba(212,175,55,0.1), rgba(212,175,55,0.2));
            border: 1px solid rgba(212,175,55,0.25);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.35rem;
            color: var(--gold-dark);
            flex-shrink: 0;
        }
        .fsm-header-text { flex: 1; min-width: 0; }
        .fsm-header-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-primary);
            margin: 0;
            line-height: 1.2;
        }
        .fsm-header-sub {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.15rem;
        }
        .fsm-header-sub strong { color: var(--gold-dark); }
        .fsm-close {
            width: 30px; height: 30px;
            background: rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.09);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem;
            color: var(--text-secondary);
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.15s, color 0.15s;
        }
        .fsm-close:hover { background: rgba(220,38,38,0.08); color: #dc2626; border-color: rgba(220,38,38,0.2); }
        .fsm-body {
            padding: 1.5rem 1.75rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        .fsm-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .fsm-field { display: flex; flex-direction: column; gap: 0.35rem; }
        .fsm-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }
        .fsm-label i { color: var(--gold-dark); margin-right: 0.2rem; }
        .fsm-label .fsm-req { color: #ef4444; margin-left: 1px; }
        .fsm-input {
            width: 100%;
            border: 1px solid rgba(37,99,235,0.15);
            border-radius: 4px;
            padding: 0.6rem 0.85rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            background: rgba(37,99,235,0.015);
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            font-family: inherit;
            outline: none;
        }
        .fsm-input:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
            background: #fff;
        }
        .fsm-input::placeholder { color: rgba(0,0,0,0.28); }
        .fsm-input.fsm-textarea { resize: vertical; min-height: 72px; }
        .fsm-prefix-wrap {
            position: relative;
        }
        .fsm-prefix-wrap .fsm-prefix {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--gold-dark);
            pointer-events: none;
        }
        .fsm-prefix-wrap .fsm-input { padding-left: 2rem; }
        .fsm-suffix-wrap { position: relative; }
        .fsm-suffix-wrap .fsm-suffix {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            pointer-events: none;
        }
        .fsm-suffix-wrap .fsm-input { padding-right: 2.2rem; }
        /* Commission preview pill */
        .fsm-comm-preview {
            background: linear-gradient(135deg, rgba(212,175,55,0.06), rgba(37,99,235,0.05));
            border: 1px solid rgba(212,175,55,0.2);
            border-radius: 6px;
            padding: 0.9rem 1.1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .fsm-comm-preview-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }
        .fsm-comm-preview-label i { color: var(--gold-dark); margin-right: 0.3rem; }
        .fsm-comm-preview-val {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--gold-dark);
            letter-spacing: -0.02em;
        }
        .fsm-comm-preview-val.fsm-dim { color: var(--text-secondary); font-size: 1rem; font-weight: 500; }
        .fsm-divider {
            height: 1px;
            background: rgba(37,99,235,0.07);
            margin: 0 -1.75rem;
        }
        .fsm-footer {
            padding: 1.1rem 1.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.65rem;
        }
        .fsm-btn {
            padding: 0.62rem 1.35rem;
            border-radius: 4px;
            font-size: 0.855rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: none;
            transition: all 0.15s;
        }
        .fsm-btn-cancel {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid rgba(0,0,0,0.12) !important;
        }
        .fsm-btn-cancel:hover { background: rgba(0,0,0,0.05); color: var(--text-primary); }
        .fsm-btn-save {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
            box-shadow: 0 4px 14px rgba(212,175,55,0.35);
            position: relative;
            overflow: hidden;
        }
        .fsm-btn-save::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; right: auto;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.18), transparent);
            transition: left 0.45s;
        }
        .fsm-btn-save:hover { filter: brightness(1.08); box-shadow: 0 6px 20px rgba(212,175,55,0.45); }
        .fsm-btn-save:hover::before { left: 100%; }
        @media (max-width: 576px) {
            .fsm-row-2 { grid-template-columns: 1fr; }
            .fsm-row-3 { grid-template-columns: 1fr; }
            .fsm-body { padding: 1.1rem 1.1rem; }
            .fsm-header { padding: 1.1rem 1.1rem 1rem; }
            .fsm-footer { padding: 1rem 1.1rem 1.25rem; }
        }

        /* ===== PROCESS PAYMENT MODAL (ppm-) ===== */
        .ppm-overlay .modal-dialog { max-width: 780px; }
        .ppm-shell.modal-content {
            background: #ffffff !important;
            border: 1px solid rgba(22,163,74,0.12) !important;
            border-radius: 8px !important;
            box-shadow: 0 28px 70px rgba(0,0,0,0.2) !important;
            overflow: hidden;
            padding: 0 !important;
            font-family: 'Inter', sans-serif;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .ppm-header {
            position: relative;
            padding: 1.5rem 1.75rem 1.25rem;
            border-bottom: 1px solid rgba(22,163,74,0.08);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .ppm-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #16a34a, #22c55e, transparent);
        }
        .ppm-header-icon {
            width: 46px; height: 46px;
            background: linear-gradient(135deg, rgba(22,163,74,0.1), rgba(22,163,74,0.2));
            border: 1px solid rgba(22,163,74,0.25);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.35rem;
            color: #16a34a;
            flex-shrink: 0;
        }
        .ppm-header-text { flex: 1; min-width: 0; }
        .ppm-header-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-primary);
            margin: 0;
            line-height: 1.2;
        }
        .ppm-header-sub {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.15rem;
        }
        .ppm-header-sub strong { color: #16a34a; }
        .ppm-close {
            width: 30px; height: 30px;
            background: rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.09);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem;
            color: var(--text-secondary);
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.15s, color 0.15s;
        }
        .ppm-close:hover { background: rgba(220,38,38,0.08); color: #dc2626; border-color: rgba(220,38,38,0.2); }
        .ppm-body {
            padding: 1.5rem 1.75rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            overflow-y: auto;
            flex: 1 1 auto;
            max-height: calc(90vh - 180px);
        }
        .ppm-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .ppm-field { display: flex; flex-direction: column; gap: 0.35rem; }
        .ppm-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }
        .ppm-label i { color: #16a34a; margin-right: 0.2rem; }
        .ppm-label .ppm-req { color: #ef4444; margin-left: 1px; }
        .ppm-input {
            width: 100%;
            border: 1px solid rgba(22,163,74,0.15);
            border-radius: 4px;
            padding: 0.6rem 0.85rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            background: rgba(22,163,74,0.015);
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            font-family: inherit;
            outline: none;
        }
        .ppm-input:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22,163,74,0.08);
            background: #fff;
        }
        .ppm-input::placeholder { color: rgba(0,0,0,0.28); }
        .ppm-input.ppm-textarea { resize: vertical; min-height: 72px; }
        .ppm-select {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 12px;
            padding-right: 2.25rem;
            cursor: pointer;
        }
        /* Commission summary */
        .ppm-comm-summary {
            background: linear-gradient(135deg, rgba(22,163,74,0.06), rgba(37,99,235,0.04));
            border: 1px solid rgba(22,163,74,0.18);
            border-radius: 6px;
            padding: 1rem 1.1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .ppm-comm-summary-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }
        .ppm-comm-summary-label i { color: #16a34a; margin-right: 0.3rem; }
        .ppm-comm-summary-val {
            font-size: 1.4rem;
            font-weight: 800;
            color: #16a34a;
            letter-spacing: -0.02em;
            margin-top: 0.2rem;
        }
        .ppm-status-badge {
            background: rgba(212,175,55,0.12);
            color: var(--gold-dark);
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.3rem 0.65rem;
            border-radius: 4px;
            border: 1px solid rgba(212,175,55,0.2);
        }
        .ppm-divider {
            height: 1px;
            background: rgba(22,163,74,0.07);
            margin: 0 -1.75rem;
        }
        /* Upload zone */
        .ppm-upload-zone {
            position: relative;
            border: 2px dashed rgba(22,163,74,0.25);
            border-radius: 6px;
            padding: 1.5rem;
            text-align: center;
            transition: border-color 0.2s, background 0.2s;
            cursor: pointer;
        }
        .ppm-upload-zone:hover,
        .ppm-upload-zone.dragover {
            border-color: #16a34a;
            background: rgba(22,163,74,0.04);
        }
        .ppm-file-input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }
        .ppm-upload-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.3rem;
        }
        .ppm-upload-icon {
            font-size: 2rem;
            color: rgba(22,163,74,0.4);
        }
        .ppm-upload-text {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .ppm-upload-hint {
            font-size: 0.72rem;
            color: var(--text-secondary);
        }
        .ppm-upload-preview {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .ppm-preview-icon {
            font-size: 1.4rem;
            color: #16a34a;
        }
        .ppm-preview-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ppm-preview-size {
            font-size: 0.72rem;
            color: var(--text-secondary);
        }
        .ppm-preview-remove {
            background: rgba(220,38,38,0.08);
            border: 1px solid rgba(220,38,38,0.15);
            border-radius: 50%;
            width: 24px; height: 24px;
            display: flex; align-items: center; justify-content: center;
            color: #dc2626;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.15s;
            z-index: 3;
        }
        .ppm-preview-remove:hover { background: rgba(220,38,38,0.15); }
        /* Warning */
        .ppm-warning {
            background: rgba(245,158,11,0.06);
            border: 1px solid rgba(245,158,11,0.2);
            border-radius: 5px;
            padding: 0.7rem 0.9rem;
            font-size: 0.78rem;
            color: #92400e;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            line-height: 1.45;
        }
        .ppm-warning i { color: #f59e0b; font-size: 0.9rem; margin-top: 1px; flex-shrink: 0; }
        .ppm-warning strong { color: #16a34a; }
        .ppm-footer {
            padding: 1.1rem 1.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.65rem;
        }
        .ppm-btn {
            padding: 0.62rem 1.35rem;
            border-radius: 4px;
            font-size: 0.855rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: none;
            transition: all 0.15s;
        }
        .ppm-btn-cancel {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid rgba(0,0,0,0.12) !important;
        }
        .ppm-btn-cancel:hover { background: rgba(0,0,0,0.05); color: var(--text-primary); }
        .ppm-btn-pay {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: #fff;
            box-shadow: 0 4px 14px rgba(22,163,74,0.35);
            position: relative;
            overflow: hidden;
        }
        .ppm-btn-pay::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; right: auto;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.18), transparent);
            transition: left 0.45s;
        }
        .ppm-btn-pay:hover { filter: brightness(1.08); box-shadow: 0 6px 20px rgba(22,163,74,0.45); }
        .ppm-btn-pay:hover::before { left: 100%; }
        .ppm-btn-pay:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            filter: none;
            box-shadow: none;
        }
        @media (max-width: 576px) {
            .ppm-row-2 { grid-template-columns: 1fr; }
            .ppm-body { padding: 1.1rem 1.1rem; }
            .ppm-header { padding: 1.1rem 1.1rem 1rem; }
            .ppm-footer { padding: 1rem 1.1rem 1.25rem; }
        }

        .cfd-container {
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid rgba(37,99,235,0.08);
            box-shadow: 0 24px 64px rgba(0,0,0,0.22);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            position: relative;
            padding: 2rem 2rem 1.75rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .cfd-top-bar {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
        }
        .cfd-top-bar.approve { background: linear-gradient(90deg, transparent, #16a34a, #22c55e, transparent); }
        .cfd-top-bar.danger  { background: linear-gradient(90deg, transparent, #dc2626, #f87171, transparent); }
        .cfd-close-btn {
            position: absolute;
            top: 0.85rem; right: 0.85rem;
            width: 28px; height: 28px;
            background: rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.09);
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.05rem;
            line-height: 1;
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
        }
        .cfd-close-btn:hover { background: rgba(220,38,38,0.08); color: #dc2626; border-color: rgba(220,38,38,0.22); }
        .cfd-icon-wrap {
            width: 76px; height: 76px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.1rem;
            margin-bottom: 1.1rem;
            flex-shrink: 0;
            transition: transform 0.2s;
        }
        .cfd-icon-wrap.approve { background: rgba(22,163,74,0.09); color: #16a34a; border: 2px solid rgba(22,163,74,0.2); }
        .cfd-icon-wrap.danger  { background: rgba(220,38,38,0.07); color: #dc2626; border: 2px solid rgba(220,38,38,0.18); }
        .cfd-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            letter-spacing: -0.01em;
        }
        .cfd-desc {
            font-size: 0.855rem;
            color: var(--text-secondary);
            line-height: 1.65;
            max-width: 330px;
            margin-bottom: 1.85rem;
        }
        .cfd-desc strong { color: var(--text-primary); }
        .cfd-footer {
            display: flex;
            gap: 0.75rem;
            width: 100%;
            justify-content: center;
        }
        .cfd-btn {
            flex: 1;
            max-width: 165px;
            padding: 0.62rem 1rem;
            border-radius: 4px;
            font-size: 0.855rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            transition: all 0.15s;
            border: none;
        }
        .cfd-cancel {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid rgba(0,0,0,0.12) !important;
        }
        .cfd-cancel:hover { background: rgba(0,0,0,0.05); color: var(--text-primary); }
        .cfd-approve {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: #fff;
            box-shadow: 0 4px 14px rgba(22,163,74,0.28);
        }
        .cfd-approve:hover { filter: brightness(1.06); box-shadow: 0 6px 18px rgba(22,163,74,0.38); }
        .cfd-danger {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: #fff;
            box-shadow: 0 4px 14px rgba(220,38,38,0.28);
        }
        .cfd-danger:hover { filter: brightness(1.06); box-shadow: 0 6px 18px rgba(220,38,38,0.38); }

        /* ===== REJECTION MODAL (rjm-) ===== */
        .rjm-container {
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid rgba(220,38,38,0.1);
            box-shadow: 0 24px 64px rgba(0,0,0,0.22);
            width: 100%;
            max-width: 460px;
            overflow: hidden;
            position: relative;
            padding: 2rem 2rem 1.75rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .rjm-top-bar {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #dc2626, #f87171, transparent);
        }
        .rjm-icon-wrap {
            width: 76px; height: 76px;
            border-radius: 50%;
            background: rgba(220,38,38,0.07);
            border: 2px solid rgba(220,38,38,0.18);
            color: #dc2626;
            font-size: 2.1rem;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 1.1rem;
            flex-shrink: 0;
        }
        .rjm-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: #dc2626;
            margin-bottom: 0.3rem;
            letter-spacing: -0.01em;
        }
        .rjm-subtitle {
            font-size: 0.82rem;
            color: var(--text-secondary);
            margin-bottom: 1.4rem;
        }
        .rjm-body {
            width: 100%;
            text-align: left;
            margin-bottom: 1.5rem;
        }
        .rjm-label {
            display: block;
            font-size: 0.76rem;
            font-weight: 700;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        .rjm-label i { color: var(--gold-dark); margin-right: 0.25rem; }
        .rjm-textarea {
            width: 100%;
            border: 1px solid rgba(220,38,38,0.22);
            border-radius: 4px;
            padding: 0.72rem 0.9rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            background: rgba(220,38,38,0.02);
            resize: vertical;
            min-height: 105px;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            font-family: inherit;
            outline: none;
            display: block;
        }
        .rjm-textarea:focus {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
            background: #fff;
        }
        .rjm-textarea::placeholder { color: rgba(0,0,0,0.3); }
        .rjm-error {
            margin-top: 0.45rem;
            font-size: 0.79rem;
            color: #dc2626;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Client-Side Rendering (CSR) Pattern
           + Progressive Hydration
           ---------------------------------------------------------------
           1. #sk-screen   : Shimmer placeholders shown on first paint.
           2. #page-content: Real server-rendered HTML, starts display:none.
           3. Hydration JS : On DOMContentLoaded, fades skeleton out and
                             fades real content in — zero layout shift.
           4. <noscript>   : If JS is disabled, skeleton is hidden and
                             real content is shown immediately.
           ================================================================ */

        /* ── Core shimmer animation ──────────────────────────────────── */
        @keyframes sk-shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .sk-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
            background-size: 1600px 100%;
            animation: sk-shimmer 1.6s ease-in-out infinite;
            border-radius: 4px;
        }

        /* ── Real content wrapper (hidden until hydration) ────────────── */
        #page-content {
            display: none; /* Revealed by JS on DOMContentLoaded */
        }

        /* ── Skeleton: Page header ────────────────────────────────────── */
        .sk-page-header {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            padding: 2rem 2.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .sk-page-header::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
        }

        /* ── Skeleton: KPI grid ───────────────────────────────────────── */
        .sk-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .sk-kpi-card {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .sk-kpi-icon { width: 48px; height: 48px; border-radius: 4px; flex-shrink: 0; }

        /* ── Skeleton: Action bar ─────────────────────────────────────── */
        .sk-action-bar {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            padding: 0.85rem 1.25rem;
            margin-bottom: 1.25rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .sk-action-bar::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
        }

        /* ── Skeleton: Status tabs ────────────────────────────────────── */
        .sk-tabs {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            margin-bottom: 1.5rem;
            padding: 0 1rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
            height: 54px;
            position: relative;
            overflow: hidden;
        }
        .sk-tabs::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
        }

        /* ── Skeleton: Sale card grid ─────────────────────────────────── */
        .sk-content-wrap {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            overflow: hidden;
        }
        .sk-sales-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 1.25rem;
            padding: 1.5rem;
        }
        .sk-sale-card {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            overflow: hidden;
        }
        .sk-card-img  { height: 180px; width: 100%; }
        .sk-card-body { padding: 1rem 1.25rem; }
        .sk-card-footer { padding: 0 1.25rem 1.25rem; }
        .sk-line { display: block; border-radius: 4px; }

        /* ── Responsive adjustments ───────────────────────────────────── */
        @media (max-width: 1200px) { .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px)  {
            .sk-kpi-grid   { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .sk-sales-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 576px)  { .sk-kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; } }
    </style>
</head>
<body>
    <?php
    $active_page = 'admin_property_sale_approvals.php';
    include 'admin_sidebar.php';
    include 'admin_navbar.php';
    ?>

    <div class="admin-content">

        <!-- ══════════════════════════════════════════════════════════════
             NO-JS FALLBACK: If JavaScript is disabled, hide skeleton
             and show real content immediately without hydration.
             ══════════════════════════════════════════════════════════════ -->
        <noscript><style>
            #sk-screen   { display: none !important; }
            #page-content { display: block !important; opacity: 1 !important; }
        </style></noscript>

        <!-- ══════════════════════════════════════════════════════════════
             SKELETON SCREEN — Visible on first paint, before JS fires.
             Mirrors the real page layout: header → KPI cards → action
             bar → status tabs → sale card grid.
             Removed from DOM by the hydration script below.
             ══════════════════════════════════════════════════════════════ -->
        <div id="sk-screen" role="presentation" aria-hidden="true" aria-label="Loading page content">

            <!-- Skeleton: Page Header -->
            <div class="sk-page-header">
                <div class="sk-line sk-shimmer" style="width:200px;height:22px;margin-bottom:10px;"></div>
                <div class="sk-line sk-shimmer" style="width:340px;height:13px;"></div>
            </div>

            <!-- Skeleton: KPI Cards -->
            <div class="sk-kpi-grid">
                <div class="sk-kpi-card">
                    <div class="sk-kpi-icon sk-shimmer"></div>
                    <div style="flex:1;"><div class="sk-line sk-shimmer" style="width:65%;height:10px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:40%;height:20px;"></div></div>
                </div>
                <div class="sk-kpi-card">
                    <div class="sk-kpi-icon sk-shimmer"></div>
                    <div style="flex:1;"><div class="sk-line sk-shimmer" style="width:70%;height:10px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:35%;height:20px;"></div></div>
                </div>
                <div class="sk-kpi-card">
                    <div class="sk-kpi-icon sk-shimmer"></div>
                    <div style="flex:1;"><div class="sk-line sk-shimmer" style="width:60%;height:10px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:30%;height:20px;"></div></div>
                </div>
                <div class="sk-kpi-card">
                    <div class="sk-kpi-icon sk-shimmer"></div>
                    <div style="flex:1;"><div class="sk-line sk-shimmer" style="width:55%;height:10px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:35%;height:20px;"></div></div>
                </div>
            </div>

            <!-- Skeleton: Action Bar -->
            <div class="sk-action-bar">
                <div class="sk-shimmer" style="flex:1;height:36px;border-radius:4px;"></div>
                <div class="sk-shimmer" style="width:90px;height:36px;border-radius:4px;flex-shrink:0;"></div>
                <div class="sk-shimmer" style="width:90px;height:36px;border-radius:4px;flex-shrink:0;"></div>
            </div>

            <!-- Skeleton: Status Tabs -->
            <div class="sk-tabs">
                <div class="sk-shimmer" style="width:75px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:85px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:80px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:80px;height:20px;border-radius:3px;"></div>
            </div>

            <!-- Skeleton: Sale Cards Grid -->
            <div class="sk-content-wrap">
                <div class="sk-sales-grid">
                    <!-- Card 1 -->
                    <div class="sk-sale-card">
                        <div class="sk-card-img sk-shimmer"></div>
                        <div class="sk-card-body">
                            <div class="sk-line sk-shimmer" style="width:82%;height:16px;margin-bottom:8px;"></div>
                            <div class="sk-line sk-shimmer" style="width:52%;height:12px;margin-bottom:12px;"></div>
                            <div style="display:flex;gap:6px;margin-bottom:12px;">
                                <div class="sk-shimmer" style="width:82px;height:10px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:95px;height:10px;border-radius:3px;"></div>
                            </div>
                            <div class="sk-line sk-shimmer" style="width:60%;height:10px;margin-bottom:16px;"></div>
                        </div>
                        <div class="sk-card-footer">
                            <div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div>
                        </div>
                    </div>
                    <!-- Card 2 -->
                    <div class="sk-sale-card">
                        <div class="sk-card-img sk-shimmer"></div>
                        <div class="sk-card-body">
                            <div class="sk-line sk-shimmer" style="width:75%;height:16px;margin-bottom:8px;"></div>
                            <div class="sk-line sk-shimmer" style="width:45%;height:12px;margin-bottom:12px;"></div>
                            <div style="display:flex;gap:6px;margin-bottom:12px;">
                                <div class="sk-shimmer" style="width:78px;height:10px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:88px;height:10px;border-radius:3px;"></div>
                            </div>
                            <div class="sk-line sk-shimmer" style="width:55%;height:10px;margin-bottom:16px;"></div>
                        </div>
                        <div class="sk-card-footer">
                            <div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div>
                        </div>
                    </div>
                    <!-- Card 3 -->
                    <div class="sk-sale-card">
                        <div class="sk-card-img sk-shimmer"></div>
                        <div class="sk-card-body">
                            <div class="sk-line sk-shimmer" style="width:88%;height:16px;margin-bottom:8px;"></div>
                            <div class="sk-line sk-shimmer" style="width:58%;height:12px;margin-bottom:12px;"></div>
                            <div style="display:flex;gap:6px;margin-bottom:12px;">
                                <div class="sk-shimmer" style="width:85px;height:10px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:92px;height:10px;border-radius:3px;"></div>
                            </div>
                            <div class="sk-line sk-shimmer" style="width:65%;height:10px;margin-bottom:16px;"></div>
                        </div>
                        <div class="sk-card-footer">
                            <div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /#sk-screen -->

        <!-- ══════════════════════════════════════════════════════════════
             REAL PAGE CONTENT — Server-rendered by PHP.
             Starts as display:none and is progressively revealed
             (with a fade-in) by the hydration script on DOMContentLoaded.
             ══════════════════════════════════════════════════════════════ -->
        <div id="page-content">

        <?php
            $needs_finalization_count = count(array_filter($sale_verifications, fn($s) =>
                $s['status'] === 'Approved' && empty($s['commission_amount'])
            ));
        $needs_payment_count = count(array_filter($sale_verifications, fn($s) =>
                $s['status'] === 'Approved' && in_array($s['commission_status'] ?? '', ['calculated', 'processing'])
            ));
        ?>
        <!--
            Toast notifications: deferred until AFTER skeleton hydration
            completes (the 'skeleton:hydrated' custom event).
            This ensures toasts only appear when the real page content
            is fully visible — no flicker, no overlap with the skeleton.
        -->
        <script>
        document.addEventListener('skeleton:hydrated', function() {
            <?php if ($success_message): ?>
                <?php
                    $toast_title = 'Success';
                    $toast_type  = 'success';
                    if (isset($_GET['success'])) {
                        switch ($_GET['success']) {
                            case 'approved':          $toast_title = 'Sale Approved'; break;
                            case 'rejected':          $toast_title = 'Verification Rejected'; break;
                            case 'finalized':         $toast_title = 'Commission Finalized'; break;
                            case 'payment_processed': $toast_title = 'Payment Processed'; $toast_type = 'success'; break;
                        }
                    }
                ?>
                showToast('<?= $toast_type ?>', '<?= $toast_title ?>', '<?= addslashes(htmlspecialchars($success_message)) ?>', 5000);
            <?php endif; ?>
            <?php if ($error_message): ?>
                showToast('error', 'Error', '<?= addslashes(htmlspecialchars($error_message)) ?>', 6000);
            <?php endif; ?>
            <?php if ($status_counts['Pending'] > 0): ?>
                setTimeout(function() {
                    showToast(
                        'warning',
                        '<?= $status_counts['Pending'] === 1 ? "1 Pending Sale Approval" : $status_counts['Pending'] . " Pending Sale Approvals" ?>',
                        '<?= $status_counts['Pending'] === 1
                            ? "1 sale verification is awaiting your review. Open it to approve or reject."
                            : $status_counts['Pending'] . " sale verifications are awaiting your review and approval." ?>',
                        6000
                    );
                }, <?= ($success_message || $error_message) ? 700 : 400 ?>);
            <?php endif; ?>
            <?php if ($needs_finalization_count > 0): ?>
                setTimeout(function() {
                    showToast(
                        'info',
                        '<?= $needs_finalization_count === 1 ? "1 Sale Needs Finalization" : $needs_finalization_count . " Sales Need Finalization" ?>',
                        '<?= $needs_finalization_count === 1
                            ? "1 approved sale is still waiting for commission to be finalized. Open the card and click <strong>Finalize Commission</strong> to complete it."
                            : $needs_finalization_count . " approved sales are still waiting for commission finalization. Look for the <strong>Needs Finalization</strong> badge on the cards." ?>',
                        8000
                    );
                }, <?= ($success_message || $error_message) ? 1400 : ($status_counts['Pending'] > 0 ? 1100 : 300) ?>);
            <?php endif; ?>
            <?php if ($needs_payment_count > 0): ?>
                setTimeout(function() {
                    showToast(
                        'money',
                        '<?= $needs_payment_count === 1 ? "1 Commission Awaiting Payment" : $needs_payment_count . " Commissions Awaiting Payment" ?>',
                        '<?= $needs_payment_count === 1
                            ? "1 finalized commission has not been paid yet. Look for the <strong>Process Payment</strong> button on the approved sale card."
                            : $needs_payment_count . " finalized commissions are still unpaid. Look for the <strong>Process Payment</strong> button on each approved sale card." ?>',
                        8000
                    );
                }, <?= ($success_message || $error_message) ? 2100 : ($status_counts['Pending'] > 0 ? 1800 : ($needs_finalization_count > 0 ? 1000 : 300)) ?>);
            <?php endif; ?>
        });
        </script>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1>Sale Approvals</h1>
                    <p class="subtitle">Review and approve property sale verifications submitted by agents</p>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="fas fa-layer-group"></i></div>
                <div><div class="kpi-label">Total Submissions</div><div class="kpi-value"><?= $status_counts['All'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fas fa-clock"></i></div>
                <div><div class="kpi-label">Pending Review</div><div class="kpi-value"><?= $status_counts['Pending'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fas fa-check-circle"></i></div>
                <div><div class="kpi-label">Approved (Sold)</div><div class="kpi-value"><?= $status_counts['Approved'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="fas fa-times-circle"></i></div>
                <div><div class="kpi-label">Rejected</div><div class="kpi-value"><?= $status_counts['Rejected'] ?></div></div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div class="action-bar-left">
                <div class="action-search-wrap">
                    <i class="bi bi-search ab-search-icon"></i>
                    <input type="text" id="quickSearchInput" placeholder="Search address, city, buyer or agent…" autocomplete="off">
                </div>
            </div>
            <div class="action-bar-right">
                <select id="sortSelect" class="sort-select" title="Sort by">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="price_high">Price: High → Low</option>
                    <option value="price_low">Price: Low → High</option>
                    <option value="agent_az">Agent A → Z</option>
                </select>
                <button class="btn-outline-admin" id="openFilterBtn">
                    <i class="bi bi-funnel"></i>
                    Filters
                    <span class="filter-count-badge" id="filterCountBadge" style="display:none;">0</span>
                </button>
            </div>
        </div>

        <!-- Status Tabs -->
        <div class="sale-tabs">
            <ul class="nav nav-tabs">
                <?php foreach ($status_tabs as $tabKey => $tabInfo): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $active_status === $tabKey ? 'active' : '' ?>"
                           href="?status=<?= $tabKey ?>"
                           data-tab="<?= $tabKey ?>">
                            <i class="bi <?= $tabInfo['icon'] ?>"></i>
                            <?= $tabKey === 'Approved' ? 'Sold' : $tabKey ?>
                            <span class="tab-badge badge-<?= strtolower($tabKey) ?>"><?= $tabInfo['count'] ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="tab-content">
                <?php
                    $display = $active_status === 'All'
                        ? $sale_verifications
                        : array_filter($sale_verifications, fn($s) => $s['status'] === $active_status);
                ?>
                <?php if (empty($display)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <h4>No <?= $active_status === 'All' ? '' : $active_status ?> Verifications</h4>
                        <p>There are no <?= strtolower($active_status) ?> sale verifications to display.</p>
                    </div>
                <?php else: ?>
                    <div class="sales-grid">
                        <?php foreach ($display as $sale):
                            $needsFinalization = ($sale['status'] === 'Approved' && empty($sale['commission_amount']));
                            $cardClass = 'sale-card' . ($needsFinalization ? ' needs-finalization' : '');
                        ?>
                            <div class="<?= $cardClass ?>" data-verification='<?= htmlspecialchars(json_encode($sale), ENT_QUOTES) ?>'>
                                <!-- Image -->
                                <div class="card-img-wrap">
                                    <?php if ($sale['property_image']): ?>
                                        <img src="<?= htmlspecialchars($sale['property_image']) ?>" alt="Property" onerror="this.src='uploads/default-property.jpg'">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#adb5bd;"><i class="bi bi-image" style="font-size:2.5rem;"></i></div>
                                    <?php endif; ?>
                                    <div class="img-overlay"></div>
                                    <?php if ($needsFinalization): ?>
                                        <div class="finalize-badge"><i class="bi bi-cash-coin"></i> Needs Finalization</div>
                                    <?php endif; ?>
                                    <div class="type-badge"><i class="bi bi-house-door"></i> <?= htmlspecialchars($sale['PropertyType']) ?></div>
                                    <?php
                                        $badgeClass = strtolower($sale['status']);
                                        $badgeLabel = $sale['status'] === 'Approved' ? 'SOLD' : strtoupper($sale['status']);
                                    ?>
                                    <div class="status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></div>
                                    <div class="price-overlay"><div class="price">₱<?= number_format($sale['sale_price'], 0) ?></div></div>
                                </div>

                                <!-- Body -->
                                <div class="card-body-content">
                                    <h3 class="prop-address" title="<?= htmlspecialchars($sale['StreetAddress']) ?>"><?= htmlspecialchars($sale['StreetAddress']) ?></h3>
                                    <div class="prop-location"><i class="bi bi-geo-alt-fill"></i><?= htmlspecialchars($sale['City']) ?></div>

                                    <div class="sale-meta-row">
                                        <span class="sale-meta-item date-meta"><i class="bi bi-calendar3"></i> <?= htmlspecialchars($sale['sale_date_fmt']) ?></span>
                                        <span class="sale-meta-item agent-meta"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($sale['agent_first_name'] . ' ' . $sale['agent_last_name']) ?></span>
                                        <?php if ($sale['document_count'] > 0): ?>
                                            <span class="sale-meta-item"><i class="bi bi-file-earmark-text"></i> <?= $sale['document_count'] ?> Doc<?= $sale['document_count'] > 1 ? 's' : '' ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="card-footer-section">
                                        <div class="posted-by"><i class="bi bi-person-fill"></i> Buyer: <?= htmlspecialchars($sale['buyer_name']) ?></div>
                                        <button class="btn-manage" onclick="viewDetails(<?= $sale['verification_id'] ?>)">
                                            <i class="bi bi-eye"></i> View Details
                                        </button>
                                        <?php if ($sale['status'] === 'Pending'): ?>
                                            <div class="pending-actions">
                                                <button class="btn-approve-sm" onclick="approveVerification(<?= $sale['verification_id'] ?>)">
                                                    <i class="bi bi-check-lg"></i> Approve
                                                </button>
                                                <button class="btn-reject-sm" onclick="rejectVerification(<?= $sale['verification_id'] ?>)">
                                                    <i class="bi bi-x-lg"></i> Reject
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="sf-no-results" id="sfNoResults" style="display:none;">
                        <i class="bi bi-funnel"></i>
                        <p>No verifications match your current filters. <a href="#" onclick="sfReset();return false;" style="color:var(--blue);font-weight:600;">Clear filters</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- ══════════════════════════════════════════════════════════════
             COMMISSION MANAGEMENT SECTION — Separate from Sale Approvals
             ══════════════════════════════════════════════════════════════ -->
        <div class="cm-section" id="commissionManagement">
            <div class="cm-section-header">
                <div class="cm-header-inner">
                    <div class="cm-header-left">
                        <h2><i class="bi bi-cash-coin"></i> Commission Management</h2>
                        <p class="cm-subtitle">Process and track agent commission payments for finalized sales</p>
                    </div>
                    <div class="cm-kpi-row">
                        <div class="cm-kpi">
                            <div class="cm-kpi-label">Total Finalized</div>
                            <div class="cm-kpi-value"><?= $commission_stats['total_finalized'] ?></div>
                        </div>
                        <div class="cm-kpi">
                            <div class="cm-kpi-label">Awaiting Payment</div>
                            <div class="cm-kpi-value"><?= $commission_stats['awaiting'] ?></div>
                        </div>
                        <div class="cm-kpi">
                            <div class="cm-kpi-label">Paid</div>
                            <div class="cm-kpi-value"><?= $commission_stats['paid'] ?></div>
                        </div>
                        <div class="cm-kpi">
                            <div class="cm-kpi-label">Unpaid Amount</div>
                            <div class="cm-kpi-value money">₱<?= number_format($commission_stats['total_unpaid_amount'], 2) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Commission Section Search -->
            <div class="cm-search-bar">
                <i class="bi bi-search cm-search-icon"></i>
                <input type="text" id="cmSearchInput" placeholder="Search by property, agent, city or reference…" autocomplete="off" oninput="cmSearch()">
                <button type="button" class="cm-search-clear" id="cmSearchClear" onclick="cmClearSearch()" title="Clear search" style="display:none;">&times;</button>
            </div>

            <!-- Toggle: Awaiting / Paid -->
            <div class="cm-toggle-row">
                <button class="cm-toggle-btn active" data-cm-tab="awaiting" onclick="cmToggleTab('awaiting')">
                    <i class="bi bi-hourglass-split"></i> Awaiting Payment
                    <span class="cm-toggle-count"><?= $commission_stats['awaiting'] ?></span>
                </button>
                <button class="cm-toggle-btn" data-cm-tab="paid" onclick="cmToggleTab('paid')">
                    <i class="bi bi-check2-all"></i> Paid
                    <span class="cm-toggle-count"><?= $commission_stats['paid'] ?></span>
                </button>
            </div>

            <!-- Awaiting Payment Table -->
            <div class="cm-table-wrap" id="cmTableAwaiting">
                <?php if (empty($commissions_for_management)): ?>
                    <div class="cm-empty-state">
                        <i class="bi bi-wallet2"></i>
                        <h4>All Commissions Paid</h4>
                        <p>There are no commissions awaiting payment processing.</p>
                    </div>
                <?php else: ?>
                    <table class="cm-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Property</th>
                                <th>Agent</th>
                                <th>Sale Price</th>
                                <th>Commission</th>
                                <th>Sale Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $cmIdx = 0; foreach ($commissions_for_management as $cm): $cmIdx++; ?>
                                <tr data-commission-id="<?= (int)$cm['commission_id'] ?>" data-verification-id="<?= (int)$cm['verification_id'] ?>">
                                    <td style="font-weight:600;color:var(--text-secondary);"><?= $cmIdx ?></td>
                                    <td>
                                        <div class="cm-prop-cell">
                                            <div class="cm-prop-addr" title="<?= htmlspecialchars($cm['StreetAddress']) ?>"><?= htmlspecialchars($cm['StreetAddress']) ?></div>
                                            <div class="cm-prop-city"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($cm['City']) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cm-agent-cell">
                                            <div class="cm-agent-avatar">
                                                <?= strtoupper(substr($cm['agent_first_name'] ?? '', 0, 1)) . strtoupper(substr($cm['agent_last_name'] ?? '', 0, 1)) ?>
                                            </div>
                                            <span class="cm-agent-name"><?= htmlspecialchars(($cm['agent_first_name'] ?? '') . ' ' . ($cm['agent_last_name'] ?? '')) ?></span>
                                        </div>
                                    </td>
                                    <td style="font-weight:600;">₱<?= number_format($cm['sale_price'], 2) ?></td>
                                    <td>
                                        <div class="cm-amount">₱<?= number_format($cm['commission_amount'], 2) ?></div>
                                        <div class="cm-rate"><?= number_format($cm['commission_percentage'] ?? 0, 1) ?>% rate</div>
                                    </td>
                                    <td><span class="cm-date"><?= htmlspecialchars($cm['sale_date_fmt']) ?></span></td>
                                    <td>
                                        <span class="cm-status-badge <?= strtolower($cm['commission_status'] ?? 'calculated') ?>">
                                            <i class="bi bi-circle-fill" style="font-size:0.4rem;"></i>
                                            <?= ucfirst($cm['commission_status'] ?? 'calculated') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="cm-actions">
                                            <button class="cm-btn-pay" onclick="cmProcessPayment(<?= (int)$cm['verification_id'] ?>)" title="Process commission payment">
                                                <i class="bi bi-wallet2"></i> Process Payment
                                            </button>
                                            <button class="cm-btn-view" onclick="viewDetails(<?= (int)$cm['verification_id'] ?>)" title="View sale details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Paid Commissions Table -->
            <div class="cm-table-wrap" id="cmTablePaid" style="display:none;">
                <?php if (empty($commissions_paid)): ?>
                    <div class="cm-empty-state">
                        <i class="bi bi-inbox"></i>
                        <h4>No Paid Commissions Yet</h4>
                        <p>Commissions will appear here once they are marked as paid.</p>
                    </div>
                <?php else: ?>
                    <table class="cm-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Property</th>
                                <th>Agent</th>
                                <th>Commission</th>
                                <th>Payment Method</th>
                                <th>Reference</th>
                                <th>Paid At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $cpIdx = 0; foreach ($commissions_paid as $cp): $cpIdx++; ?>
                                <tr>
                                    <td style="font-weight:600;color:var(--text-secondary);"><?= $cpIdx ?></td>
                                    <td>
                                        <div class="cm-prop-cell">
                                            <div class="cm-prop-addr" title="<?= htmlspecialchars($cp['StreetAddress']) ?>"><?= htmlspecialchars($cp['StreetAddress']) ?></div>
                                            <div class="cm-prop-city"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($cp['City']) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cm-agent-cell">
                                            <div class="cm-agent-avatar">
                                                <?= strtoupper(substr($cp['agent_first_name'] ?? '', 0, 1)) . strtoupper(substr($cp['agent_last_name'] ?? '', 0, 1)) ?>
                                            </div>
                                            <span class="cm-agent-name"><?= htmlspecialchars(($cp['agent_first_name'] ?? '') . ' ' . ($cp['agent_last_name'] ?? '')) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cm-amount">₱<?= number_format($cp['commission_amount'], 2) ?></div>
                                        <div class="cm-rate"><?= number_format($cp['commission_percentage'] ?? 0, 1) ?>% rate</div>
                                    </td>
                                    <td style="font-weight:600; text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $cp['commission_payment_method'] ?? '—')) ?></td>
                                    <td>
                                        <code style="font-size:0.78rem;background:rgba(34,197,94,0.06);padding:0.15rem 0.4rem;border-radius:3px;">
                                            <?= htmlspecialchars($cp['commission_payment_ref'] ?? '—') ?>
                                        </code>
                                    </td>
                                    <td>
                                        <span class="cm-date">
                                            <?= $cp['commission_paid_at'] ? date('M j, Y g:i A', strtotime($cp['commission_paid_at'])) : '—' ?>
                                        </span>
                                        <?php if (!empty($cp['paid_by_first'])): ?>
                                            <div style="font-size:0.7rem;color:var(--text-secondary);">by <?= htmlspecialchars($cp['paid_by_first'] . ' ' . $cp['paid_by_last']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="cm-btn-view" onclick="viewDetails(<?= (int)$cp['verification_id'] ?>)" title="View sale details">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        </div><!-- /#page-content -->
    </div><!-- /.admin-content -->

    <!-- View Details Modal -->
    <div class="modal-overlay" id="detailsModal">
        <div class="modal-container modal-large">
            <div class="modal-admin-header">
                <h2><i class="bi bi-file-earmark-check"></i> Sale Verification Details</h2>
                <div class="modal-header-meta">
                    <span class="modal-vid-badge" id="modalVidBadge"></span>
                    <button class="modal-close-btn" onclick="closeModal('detailsModal')">&times;</button>
                </div>
            </div>
            <div class="modal-body" id="modalContent"></div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </div>

    <!-- Document Preview Modal -->
    <div class="modal-overlay" id="previewModal">
        <div class="modal-container modal-large">
            <div class="modal-admin-header">
                <h2 id="previewTitle"><i class="bi bi-file-earmark-text"></i> Document Preview</h2>
                <button class="modal-close-btn" onclick="closeModal('previewModal')">&times;</button>
            </div>
            <div class="modal-body" id="previewContent"></div>
            <div class="modal-footer">
                <button class="btn-modal btn-modal-blue" id="downloadBtn" onclick="downloadCurrentDocument()"><i class="bi bi-download me-1"></i>Download</button>
                <button class="btn-modal btn-modal-secondary" onclick="closeModal('previewModal')"><i class="bi bi-x-lg me-1"></i>Close</button>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmModal">
        <div class="cfd-container">
            <div class="cfd-top-bar" id="cfdTopBar"></div>
            <button class="cfd-close-btn" onclick="closeModal('confirmModal')">&times;</button>
            <div class="cfd-icon-wrap" id="cfdIconWrap"></div>
            <div class="cfd-title" id="cfdTitle">Confirm Action</div>
            <div class="cfd-desc" id="cfdDesc"></div>
            <div class="cfd-footer">
                <button class="cfd-btn cfd-cancel" onclick="closeModal('confirmModal')"><i class="bi bi-x-lg"></i> Cancel</button>
                <button class="cfd-btn cfd-approve" id="confirmActionBtn"><i class="bi bi-check-lg"></i> Confirm</button>
            </div>
        </div>
    </div>

    <!-- Rejection Reason Modal -->
    <div class="modal-overlay" id="reasonModal">
        <div class="rjm-container">
            <div class="rjm-top-bar"></div>
            <button class="cfd-close-btn" onclick="closeModal('reasonModal')">&times;</button>
            <div class="rjm-icon-wrap"><i class="bi bi-x-octagon-fill"></i></div>
            <div class="rjm-title">Reject Verification</div>
            <div class="rjm-subtitle">Provide a clear reason so the agent can understand and resubmit</div>
            <div class="rjm-body">
                <label class="rjm-label" for="reasonInput"><i class="bi bi-chat-left-text"></i> Rejection Reason</label>
                <textarea class="rjm-textarea" id="reasonInput" placeholder="Explain why this verification is being rejected..."></textarea>
                <div id="reasonError" class="rjm-error" style="display:none;"><i class="bi bi-exclamation-circle"></i> A reason is required.</div>
            </div>
            <div class="cfd-footer">
                <button class="cfd-btn cfd-cancel" onclick="closeModal('reasonModal')"><i class="bi bi-x-lg"></i> Cancel</button>
                <button class="cfd-btn cfd-danger" id="submitRejectBtn"><i class="bi bi-x-octagon"></i> Reject</button>
            </div>
        </div>
    </div>

    <!-- Finalize Sale & Commission Modal -->
    <div class="modal fade fsm-overlay" id="finalizeSaleModal" tabindex="-1" aria-labelledby="finalizeSaleLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="fsm-shell modal-content">
                <div class="fsm-header">
                    <div class="fsm-header-icon"><i class="bi bi-cash-coin"></i></div>
                    <div class="fsm-header-text">
                        <h5 class="fsm-header-title" id="finalizeSaleLabel">Finalize Sale &amp; Commission</h5>
                        <div class="fsm-header-sub" id="finalizeHelp">Loading&hellip;</div>
                    </div>
                    <button type="button" class="fsm-close" data-bs-dismiss="modal" aria-label="Close">&times;</button>
                </div>
                <form id="finalizeSaleForm">
                    <div class="fsm-body">
                        <input type="hidden" name="property_id" id="finalize_property_id">
                        <input type="hidden" name="agent_id"    id="finalize_agent_id">

                        <!-- Row 1: Sale Price + Commission Rate + Live Preview -->
                        <div class="fsm-row-3">
                            <div class="fsm-field" style="grid-column:1/2;">
                                <label class="fsm-label" for="final_sale_price"><i class="bi bi-tag-fill"></i> Final Sale Price <span class="fsm-req">*</span></label>
                                <div class="fsm-prefix-wrap">
                                    <span class="fsm-prefix">&#8369;</span>
                                    <input type="number" step="0.01" min="0" class="fsm-input" id="final_sale_price" name="final_sale_price"
                                           placeholder="0.00" required oninput="fsmCalc()">
                                </div>
                            </div>
                            <div class="fsm-field" style="grid-column:2/3;">
                                <label class="fsm-label" for="commission_percentage"><i class="bi bi-percent"></i> Commission Rate <span class="fsm-req">*</span></label>
                                <div class="fsm-suffix-wrap">
                                    <input type="number" step="0.01" min="0" max="100" class="fsm-input" id="commission_percentage" name="commission_percentage"
                                           placeholder="e.g. 3" required oninput="fsmCalc()">
                                    <span class="fsm-suffix">%</span>
                                </div>
                            </div>
                            <div class="fsm-field" style="grid-column:3/4;">
                                <div class="fsm-comm-preview" id="fsmCommPreview" style="height:100%;">
                                    <div>
                                        <div class="fsm-comm-preview-label"><i class="bi bi-coin"></i> Commission</div>
                                        <div class="fsm-comm-preview-val fsm-dim" id="fsmCommVal">&mdash;</div>
                                    </div>
                                    <i class="bi bi-calculator" style="font-size:1.5rem;color:rgba(212,175,55,0.3);"></i>
                                </div>
                            </div>
                        </div>

                        <div class="fsm-divider"></div>

                        <!-- Row 2: Buyer Name + Email (2 col) -->
                        <div class="fsm-row-2">
                            <div class="fsm-field">
                                <label class="fsm-label" for="buyer_name"><i class="bi bi-person-fill"></i> Buyer Name</label>
                                <input type="text" class="fsm-input" id="buyer_name" name="buyer_name" placeholder="Full name">
                            </div>
                            <div class="fsm-field">
                                <label class="fsm-label" for="buyer_email"><i class="bi bi-envelope-fill"></i> Buyer Email</label>
                                <input type="email" class="fsm-input" id="buyer_email" name="buyer_email" placeholder="email@example.com">
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="fsm-field">
                            <label class="fsm-label" for="notes"><i class="bi bi-chat-left-text"></i> Notes <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                            <textarea class="fsm-input fsm-textarea" id="notes" name="notes" placeholder="Any additional notes about this commission..."></textarea>
                        </div>
                    </div>
                    <div class="fsm-footer">
                        <button type="button" class="fsm-btn fsm-btn-cancel" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                        <button type="submit" class="fsm-btn fsm-btn-save"><i class="bi bi-check2-circle"></i> Save &amp; Calculate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Processing Overlay -->
    <!-- Process Commission Payment Modal (ppm-) -->
    <div class="modal fade ppm-overlay" id="processPaymentModal" tabindex="-1" aria-labelledby="processPaymentLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="ppm-shell modal-content">
                <div class="ppm-header">
                    <div class="ppm-header-icon"><i class="bi bi-wallet2"></i></div>
                    <div class="ppm-header-text">
                        <h5 class="ppm-header-title" id="processPaymentLabel">Process Commission Payment</h5>
                        <div class="ppm-header-sub" id="ppmHelp">Loading&hellip;</div>
                    </div>
                    <button type="button" class="ppm-close" data-bs-dismiss="modal" aria-label="Close">&times;</button>
                </div>
                <form id="processPaymentForm" enctype="multipart/form-data">
                    <div class="ppm-body">
                        <input type="hidden" name="commission_id" id="ppm_commission_id">

                        <!-- Commission summary -->
                        <div class="ppm-comm-summary">
                            <div class="ppm-comm-summary-left">
                                <div class="ppm-comm-summary-label"><i class="bi bi-coin"></i> Commission Amount</div>
                                <div class="ppm-comm-summary-val" id="ppmCommAmount">&mdash;</div>
                            </div>
                            <div class="ppm-comm-summary-right">
                                <span class="ppm-status-badge" id="ppmStatusBadge">Calculated</span>
                            </div>
                        </div>

                        <div class="ppm-divider"></div>

                        <!-- Row: Payment Method + Reference -->
                        <div class="ppm-row-2">
                            <div class="ppm-field">
                                <label class="ppm-label" for="ppm_payment_method"><i class="bi bi-credit-card-2-front"></i> Payment Method <span class="ppm-req">*</span></label>
                                <select class="ppm-input ppm-select" id="ppm_payment_method" name="payment_method" required>
                                    <option value="" disabled selected>Select method…</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="gcash">GCash</option>
                                    <option value="maya">Maya</option>
                                    <option value="cash">Cash</option>
                                    <option value="check">Check</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="ppm-field">
                                <label class="ppm-label" for="ppm_payment_reference"><i class="bi bi-hash"></i> Transaction Reference <span class="ppm-req">*</span></label>
                                <input type="text" class="ppm-input" id="ppm_payment_reference" name="payment_reference"
                                       placeholder="e.g. BDO-TXN-20260302-001" required maxlength="100">
                            </div>
                        </div>

                        <!-- File Upload -->
                        <div class="ppm-field">
                            <label class="ppm-label"><i class="bi bi-file-earmark-arrow-up"></i> Payment Proof <span class="ppm-req">*</span></label>
                            <div class="ppm-upload-zone" id="ppmUploadZone">
                                <input type="file" name="payment_proof" id="ppm_payment_proof" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf"
                                       class="ppm-file-input" required>
                                <div class="ppm-upload-content" id="ppmUploadContent">
                                    <i class="bi bi-cloud-arrow-up ppm-upload-icon"></i>
                                    <span class="ppm-upload-text">Click to upload or drag &amp; drop</span>
                                    <span class="ppm-upload-hint">JPG, PNG, WEBP, GIF, PDF — Max 5 MB</span>
                                </div>
                                <div class="ppm-upload-preview" id="ppmUploadPreview" style="display:none;">
                                    <i class="bi bi-file-earmark-check ppm-preview-icon"></i>
                                    <span class="ppm-preview-name" id="ppmPreviewName"></span>
                                    <span class="ppm-preview-size" id="ppmPreviewSize"></span>
                                    <button type="button" class="ppm-preview-remove" id="ppmRemoveFile" title="Remove file">&times;</button>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="ppm-field">
                            <label class="ppm-label" for="ppm_payment_notes"><i class="bi bi-chat-left-text"></i> Payment Notes <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                            <textarea class="ppm-input ppm-textarea" id="ppm_payment_notes" name="payment_notes"
                                      placeholder="Any additional notes about this payment..."></textarea>
                        </div>

                        <!-- Warning -->
                        <div class="ppm-warning">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <span>This action cannot be undone. The commission will be permanently marked as <strong>PAID</strong>.</span>
                        </div>
                    </div>
                    <div class="ppm-footer">
                        <button type="button" class="ppm-btn ppm-btn-cancel" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                        <button type="submit" class="ppm-btn ppm-btn-pay" id="ppmSubmitBtn"><i class="bi bi-check2-circle"></i> Confirm Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Processing Overlay -->
    <div id="processingOverlay" class="processing-overlay">
        <div class="processing-card">
            <div class="pc-header">
                <div class="pc-ring-wrap">
                    <div class="pc-ring"></div>
                    <div class="pc-ring-inner"></div>
                    <div class="pc-icon-center"><i class="bi bi-cash-coin" id="pcIcon"></i></div>
                </div>
                <div class="pc-title" id="pcTitle">Finalizing Sale</div>
                <div class="pc-subtitle" id="pcSubtitle">Please wait&hellip;</div>
            </div>
            <div class="pc-steps-wrap">
                <div class="pc-steps">
                    <div class="pc-step" id="pcStep1">
                        <div class="pc-step-dot"><i class="bi bi-check-lg"></i></div>
                        <span>Validating sale data</span>
                    </div>
                    <div class="pc-step" id="pcStep2">
                        <div class="pc-step-dot"><i class="bi bi-check-lg"></i></div>
                        <span>Saving finalized record</span>
                    </div>
                    <div class="pc-step" id="pcStep3">
                        <div class="pc-step-dot"><i class="bi bi-check-lg"></i></div>
                        <span>Calculating commission</span>
                    </div>
                    <div class="pc-step" id="pcStep4">
                        <div class="pc-step-dot"><i class="bi bi-envelope-paper"></i></div>
                        <span>Sending email notification</span>
                    </div>
                </div>
            </div>
            <div class="pc-progress">
                <div class="pc-progress-bar" id="pcProgressBar"></div>
            </div>
        </div>
    </div>

    <!-- Advanced Filter Sidebar -->
    <div class="sf-sidebar" id="sfSidebar">
        <div class="sf-overlay" id="sfOverlay"></div>
        <div class="sf-content">
            <div class="sf-header">
                <h4><i class="bi bi-funnel-fill"></i> Advanced Filters</h4>
                <div class="sf-header-right">
                    <span class="sf-active-pill" id="sfActivePill"><i class="bi bi-check2"></i> <span id="sfActivePillText">0 active</span></span>
                    <button class="btn-close-sf" id="sfCloseBtn"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>

            <div class="sf-results-bar">
                <i class="bi bi-list-check"></i>
                <span class="sf-results-num" id="sfResultsNum">—</span>
                <span class="sf-results-label">verifications match your filters</span>
            </div>

            <div class="sf-body">
                <!-- Search -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-search"></i> Search</div>
                    <div class="sf-search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" id="sfSearchInput" placeholder="Address, city, buyer name, agent name…">
                    </div>
                </div>

                <!-- Sale Price -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-cash-stack"></i> Sale Price Range</div>
                    <div class="price-slider-container">
                        <div class="price-slider-track">
                            <div class="price-slider-range" id="sfPriceSliderRange"></div>
                        </div>
                        <input type="range" id="sfPriceMinSlider" class="price-range-slider" min="0" max="100000000" value="0" step="100000">
                        <input type="range" id="sfPriceMaxSlider" class="price-range-slider" min="0" max="100000000" value="100000000" step="100000">
                    </div>
                    <div class="price-range-inputs">
                        <div class="price-input">
                            <span class="currency-sym">₱</span>
                            <input type="text" id="sfPriceMinDisplay" placeholder="Min" readonly>
                        </div>
                        <span class="range-divider">—</span>
                        <div class="price-input">
                            <span class="currency-sym">₱</span>
                            <input type="text" id="sfPriceMaxDisplay" placeholder="Max" readonly>
                        </div>
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-price-range="0-5000000">Under 5M</button>
                        <button class="quick-filter-btn" data-price-range="5000000-15000000">5M – 15M</button>
                        <button class="quick-filter-btn" data-price-range="15000000-30000000">15M – 30M</button>
                        <button class="quick-filter-btn" data-price-range="30000000-999999999">30M+</button>
                    </div>
                </div>

                <!-- Property Type -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-house-door"></i> Property Type</div>
                    <div class="filter-chips" id="sfPropertyTypes"></div>
                </div>

                <!-- Commission Status -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-cash-coin"></i> Commission Status</div>
                    <div class="filter-chips">
                        <div class="filter-chip active" data-commission-status="all">All</div>
                        <div class="filter-chip" data-commission-status="needs_finalization"><i class="bi bi-exclamation-circle me-1 text-warning"></i>Needs Finalization</div>
                        <div class="filter-chip" data-commission-status="finalized"><i class="bi bi-check-circle me-1" style="color:#16a34a"></i>Finalized</div>
                        <div class="filter-chip" data-commission-status="awaiting_payment"><i class="bi bi-hourglass-split me-1" style="color:#d97706"></i>Awaiting Payment</div>
                        <div class="filter-chip" data-commission-status="paid"><i class="bi bi-check2-all me-1" style="color:#16a34a"></i>Paid</div>
                    </div>
                </div>

                <!-- Sale Date -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-calendar3"></i> Sale Date</div>
                    <div class="date-range-inputs">
                        <input type="date" id="sfDateFrom" title="Sale date from">
                        <span class="range-divider">—</span>
                        <input type="date" id="sfDateTo" title="Sale date to">
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-date-range="this_month">This Month</button>
                        <button class="quick-filter-btn" data-date-range="last_30">Last 30 Days</button>
                        <button class="quick-filter-btn" data-date-range="this_year">This Year</button>
                    </div>
                </div>

                <!-- Location & Agent -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-geo-alt"></i> Location &amp; Agent</div>
                    <div class="row g-2">
                        <div class="col-12">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;">City</label>
                            <select id="sfCitySelect" class="sf-select mt-1">
                                <option value="">All Cities</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;">Agent</label>
                            <select id="sfAgentSelect" class="sf-select mt-1">
                                <option value="">All Agents</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sf-footer">
                <button class="btn btn-reset" id="sfResetBtn"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset All</button>
                <button class="btn btn-apply" id="sfApplyBtn"><i class="bi bi-check2 me-1"></i>Apply Filters</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
    // ===== DATA =====
    const saleVerifications = <?= json_encode($sale_verifications) ?>;
    let currentViewedSale = null;
    let currentDocId = null, currentDocName = '';

    // ===== MODAL HELPERS =====
    function _lockScroll()   { const sw = window.innerWidth - document.documentElement.clientWidth; document.body.style.paddingRight = sw + 'px'; document.body.style.overflow = 'hidden'; }
    function _unlockScroll() { document.body.style.overflow = ''; document.body.style.paddingRight = ''; }
    function openModal(id) { document.getElementById(id).classList.add('show'); _lockScroll(); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); _unlockScroll(); }
    document.querySelectorAll('.modal-overlay').forEach(o => {
        o.addEventListener('click', e => { if (e.target === o) closeModal(o.id); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => closeModal(m.id));
    });

    // Track step timer IDs so we can cancel them on early hide
    let _pcTimers = [];

    function showProcessing(msg, mode) {
        const o = document.getElementById('processingOverlay');
        const allSteps = ['pcStep1','pcStep2','pcStep3','pcStep4'];
        const bar = document.getElementById('pcProgressBar');
        const icon = document.getElementById('pcIcon');

        // Clear any pending timers
        _pcTimers.forEach(t => clearTimeout(t));
        _pcTimers = [];

        // Reset steps & progress
        allSteps.forEach(id => {
            const el = document.getElementById(id);
            el.classList.remove('active','done');
            el.style.display = '';
        });
        if (bar) bar.style.width = '0%';

        const title = document.getElementById('pcTitle');
        const sub   = document.getElementById('pcSubtitle');

        if (mode === 'approve') {
            title.textContent = 'Approving Sale';
            sub.textContent   = 'Updating records\u2026';
            icon.className = 'bi bi-check-circle';
            document.getElementById('pcStep1').querySelector('span').textContent = 'Verifying submission';
            document.getElementById('pcStep2').querySelector('span').textContent = 'Marking property as sold';
            document.getElementById('pcStep3').querySelector('span').textContent = 'Sending agent notification';
            document.getElementById('pcStep4').style.display = 'none';
        } else if (mode === 'reject') {
            title.textContent = 'Rejecting Verification';
            sub.textContent   = 'Processing\u2026';
            icon.className = 'bi bi-x-circle';
            document.getElementById('pcStep1').querySelector('span').textContent = 'Validating request';
            document.getElementById('pcStep2').querySelector('span').textContent = 'Updating property status';
            document.getElementById('pcStep3').querySelector('span').textContent = 'Sending agent notification';
            document.getElementById('pcStep4').style.display = 'none';
        } else if (mode === 'payment') {
            title.textContent = 'Processing Payment';
            sub.textContent   = 'Marking commission as paid\u2026';
            icon.className = 'bi bi-wallet2';
            document.getElementById('pcStep1').querySelector('span').textContent = 'Validating payment data';
            document.getElementById('pcStep2').querySelector('span').textContent = 'Uploading payment proof';
            document.getElementById('pcStep3').querySelector('span').textContent = 'Updating commission record';
            document.getElementById('pcStep4').querySelector('span').textContent = 'Sending agent notification';
            document.getElementById('pcStep4').style.display = '';
        } else {
            title.textContent = 'Finalizing Sale';
            sub.textContent   = 'Processing your request\u2026';
            icon.className = 'bi bi-cash-coin';
            document.getElementById('pcStep1').querySelector('span').textContent = 'Validating sale data';
            document.getElementById('pcStep2').querySelector('span').textContent = 'Saving finalized record';
            document.getElementById('pcStep3').querySelector('span').textContent = 'Calculating commission';
            document.getElementById('pcStep4').querySelector('span').textContent = 'Sending email notification';
            document.getElementById('pcStep4').style.display = '';
        }

        // Determine visible steps and timings
        const isFinalize = (mode !== 'approve' && mode !== 'reject');
        const visibleSteps = isFinalize ? allSteps : allSteps.slice(0, 3);
        const stepCount = visibleSteps.length;
        // Longer delays for finalize/payment (email adds time)
        const baseDelay = isFinalize ? 800 : 550;

        // Animate steps sequentially
        const stepEl = (i) => document.getElementById(visibleSteps[i]);
        stepEl(0).classList.add('active');
        if (bar) bar.style.width = (100 / (stepCount + 1)) + '%';

        for (let i = 1; i < stepCount; i++) {
            const delay = baseDelay * i;
            const t = setTimeout(() => {
                stepEl(i - 1).classList.remove('active');
                stepEl(i - 1).classList.add('done');
                stepEl(i).classList.add('active');
                if (bar) bar.style.width = (((i + 1) / (stepCount + 1)) * 100) + '%';
            }, delay);
            _pcTimers.push(t);
        }

        o.classList.add('show');
        _lockScroll();
    }

    function hideProcessing() {
        const o = document.getElementById('processingOverlay');
        const allSteps = ['pcStep1','pcStep2','pcStep3','pcStep4'];
        const bar = document.getElementById('pcProgressBar');

        // Cancel pending animations
        _pcTimers.forEach(t => clearTimeout(t));
        _pcTimers = [];

        // Mark all visible steps as done
        allSteps.forEach(id => {
            const el = document.getElementById(id);
            if (el.style.display !== 'none') {
                el.classList.remove('active');
                el.classList.add('done');
            }
        });
        if (bar) bar.style.width = '100%';

        setTimeout(() => {
            o.classList.remove('show');
            _unlockScroll();
        }, 400);
    }

    // ===== TOAST =====
    function showToast(type, title, message, duration) {
        duration = duration || 4500;
        const container = document.getElementById('toastContainer');
        const icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', info: 'bi-info-circle-fill', warning: 'bi-exclamation-triangle-fill', money: 'bi-cash-coin' };
        const toast = document.createElement('div');
        toast.className = `app-toast toast-${type}`;
        toast.innerHTML = `
            <div class="app-toast-icon"><i class="bi ${icons[type] || icons.info}"></i></div>
            <div class="app-toast-body">
                <div class="app-toast-title">${title}</div>
                <div class="app-toast-msg">${message}</div>
            </div>
            <button class="app-toast-close" onclick="dismissToast(this.closest('.app-toast'))">&times;</button>
            <div class="app-toast-progress" style="animation: toast-progress ${duration}ms linear forwards;"></div>
        `;
        container.appendChild(toast);
        const timer = setTimeout(() => dismissToast(toast), duration);
        toast._timer = timer;
    }
    function dismissToast(toast) {
        if (!toast || toast._dismissed) return;
        toast._dismissed = true;
        clearTimeout(toast._timer);
        toast.classList.add('toast-out');
        setTimeout(() => toast.remove(), 320);
    }

    // ===== ADVANCED FILTER SYSTEM =====
    const sf = { search: '', priceMin: 0, priceMax: 999999999, typeFilter: new Set(), commissionStatus: 'all', saleDateFrom: '', saleDateTo: '', city: '', agent: '', sort: 'newest', _maxPrice: 0, _allTypes: [] };
    let _cardMap = null;

    function sfBuildCardMap() {
        _cardMap = new Map();
        document.querySelectorAll('.sale-card').forEach(card => {
            try { const d = JSON.parse(card.dataset.verification); _cardMap.set(d.verification_id, card); } catch(e) {}
        });
    }

    function sfInit() {
        // Dynamic options from data
        const types = [...new Set(saleVerifications.map(s => s.PropertyType).filter(Boolean))].sort();
        sf._allTypes = types;
        const typeWrap = document.getElementById('sfPropertyTypes');
        if (typeWrap) {
            typeWrap.innerHTML = types.map(t =>
                `<label class="filter-chip active"><input type="checkbox" class="sf-type-cb" value="${esc(t)}" checked><span>${esc(t)}</span></label>`
            ).join('');
            typeWrap.querySelectorAll('.filter-chip').forEach(chip => {
                chip.addEventListener('click', e => {
                    if (e.target.type === 'checkbox') return;
                    e.preventDefault();
                    const cb = chip.querySelector('input');
                    cb.checked = !cb.checked;
                    chip.classList.toggle('active', cb.checked);
                });
                chip.querySelector('input').addEventListener('change', function() {
                    chip.classList.toggle('active', this.checked);
                });
            });
        }

        // Agents
        const agentMap = new Map();
        saleVerifications.forEach(s => { const n = ((s.agent_first_name||'')+' '+(s.agent_last_name||'')).trim(); if (s.agent_id && n) agentMap.set(String(s.agent_id), n); });
        const agSel = document.getElementById('sfAgentSelect');
        if (agSel) agSel.innerHTML = '<option value="">All Agents</option>' + [...agentMap.entries()].map(([id,n])=>`<option value="${id}">${esc(n)}</option>`).join('');

        // Cities
        const cities = [...new Set(saleVerifications.map(s => s.City).filter(Boolean))].sort();
        const citySel = document.getElementById('sfCitySelect');
        if (citySel) citySel.innerHTML = '<option value="">All Cities</option>' + cities.map(c=>`<option value="${esc(c)}">${esc(c)}</option>`).join('');

        // Price slider max
        const prices = saleVerifications.map(s => Number(s.sale_price)).filter(p => p > 0);
        const maxP = prices.length ? Math.ceil(Math.max(...prices) / 1000000) * 1000000 : 50000000;
        sf._maxPrice = maxP;
        const minSl = document.getElementById('sfPriceMinSlider'), maxSl = document.getElementById('sfPriceMaxSlider');
        if (minSl) { minSl.max = maxP; minSl.value = 0; }
        if (maxSl) { maxSl.max = maxP; maxSl.value = maxP; }
        sfUpdatePriceSlider();

        // Commission status chips
        document.querySelectorAll('[data-commission-status]').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('[data-commission-status]').forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
            });
        });

        // Price quick-filters
        document.querySelectorAll('[data-price-range]').forEach(btn => {
            btn.addEventListener('click', () => {
                const [lo, hi] = btn.dataset.priceRange.split('-').map(Number);
                if (minSl) { minSl.value = lo; }
                if (maxSl) { maxSl.value = Math.min(hi, maxP); }
                sfUpdatePriceSlider();
                document.querySelectorAll('[data-price-range]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });

        // Date quick-filters
        document.querySelectorAll('[data-date-range]').forEach(btn => {
            btn.addEventListener('click', () => {
                const now = new Date(), pad = n => String(n).padStart(2,'0');
                const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
                let from = '', to = fmt(now);
                if (btn.dataset.dateRange === 'this_month') {
                    from = `${now.getFullYear()}-${pad(now.getMonth()+1)}-01`;
                } else if (btn.dataset.dateRange === 'last_30') {
                    const d = new Date(now); d.setDate(d.getDate()-30); from = fmt(d);
                } else if (btn.dataset.dateRange === 'this_year') {
                    from = `${now.getFullYear()}-01-01`;
                }
                const df = document.getElementById('sfDateFrom'), dt = document.getElementById('sfDateTo');
                if (df) df.value = from;
                if (dt) dt.value = to;
                document.querySelectorAll('[data-date-range]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });

        // Price slider live
        ['sfPriceMinSlider','sfPriceMaxSlider'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', () => { sfUpdatePriceSlider(); document.querySelectorAll('[data-price-range]').forEach(b=>b.classList.remove('active')); });
        });

        // Quick search live
        const qs = document.getElementById('quickSearchInput');
        if (qs) qs.addEventListener('input', () => { document.getElementById('sfSearchInput') && (document.getElementById('sfSearchInput').value = qs.value); sfApply(); });

        // Sort live
        const sortSel = document.getElementById('sortSelect');
        if (sortSel) sortSel.addEventListener('change', sfApply);

        // Sidebar open/close
        document.getElementById('openFilterBtn')?.addEventListener('click', sfOpen);
        document.getElementById('sfCloseBtn')?.addEventListener('click', sfClose);
        document.getElementById('sfOverlay')?.addEventListener('click', sfClose);
        document.getElementById('sfApplyBtn')?.addEventListener('click', () => { sfApply(); sfClose(); });
        document.getElementById('sfResetBtn')?.addEventListener('click', sfReset);

        // Search inside sidebar live
        document.getElementById('sfSearchInput')?.addEventListener('input', sfPreview);

        sfBuildCardMap();
        sfApply(); // initial run
    }

    function sfUpdatePriceSlider() {
        const lo = Number(document.getElementById('sfPriceMinSlider')?.value || 0);
        const hi = Number(document.getElementById('sfPriceMaxSlider')?.value || sf._maxPrice);
        const max = sf._maxPrice || 1;
        const loP = (lo / max * 100).toFixed(2);
        const hiP = (hi / max * 100).toFixed(2);
        const range = document.getElementById('sfPriceSliderRange');
        if (range) { range.style.left = loP + '%'; range.style.width = (hiP - loP) + '%'; }
        const fmt = n => '₱' + Number(n).toLocaleString('en-PH', {maximumFractionDigits:0});
        const dMin = document.getElementById('sfPriceMinDisplay'), dMax = document.getElementById('sfPriceMaxDisplay');
        if (dMin) dMin.value = lo > 0 ? fmt(lo) : '';
        if (dMax) dMax.value = hi < sf._maxPrice ? fmt(hi) : '';
    }

    function sfReadState() {
        sf.search = (document.getElementById('sfSearchInput')?.value || document.getElementById('quickSearchInput')?.value || '').toLowerCase().trim();
        sf.priceMin = Number(document.getElementById('sfPriceMinSlider')?.value || 0);
        sf.priceMax = Number(document.getElementById('sfPriceMaxSlider')?.value || sf._maxPrice || 999999999);
        // types
        const cbs = document.querySelectorAll('.sf-type-cb');
        sf.typeFilter = new Set();
        let anyUnchecked = false;
        cbs.forEach(cb => { if (!cb.checked) anyUnchecked = true; else sf.typeFilter.add(cb.value); });
        sf._typeFilterActive = anyUnchecked;
        // commission
        const commChip = document.querySelector('[data-commission-status].active');
        sf.commissionStatus = commChip ? commChip.dataset.commissionStatus : 'all';
        sf.saleDateFrom = document.getElementById('sfDateFrom')?.value || '';
        sf.saleDateTo   = document.getElementById('sfDateTo')?.value || '';
        sf.city  = document.getElementById('sfCitySelect')?.value || '';
        sf.agent = document.getElementById('sfAgentSelect')?.value || '';
        sf.sort  = document.getElementById('sortSelect')?.value || 'newest';
    }

    function sfCountActive() {
        let n = 0;
        if (sf.search) n++;
        if (sf.priceMin > 0) n++;
        if (sf.priceMax < (sf._maxPrice || 999999999)) n++;
        if (sf._typeFilterActive) n++;
        if (sf.commissionStatus !== 'all') n++;
        if (sf.saleDateFrom) n++;
        if (sf.saleDateTo)   n++;
        if (sf.city)  n++;
        if (sf.agent) n++;
        return n;
    }

    function sfGetTabStatus() {
        return document.querySelector('.sale-tabs .nav-link.active')?.dataset.tab || 'All';
    }

    function sfFilterAndSort() {
        sfReadState();
        const tabStatus = sfGetTabStatus();
        let matches = saleVerifications.filter(s => {
            if (tabStatus !== 'All' && s.status !== tabStatus) return false;
            if (sf.search) {
                const hay = [s.StreetAddress, s.City, s.buyer_name, s.buyer_email, s.agent_first_name, s.agent_last_name, s.PropertyType].join(' ').toLowerCase();
                if (!hay.includes(sf.search)) return false;
            }
            const price = Number(s.sale_price);
            if (sf.priceMin > 0 && price < sf.priceMin) return false;
            if (sf.priceMax < (sf._maxPrice || 999999999) && price > sf.priceMax) return false;
            if (sf._typeFilterActive && !sf.typeFilter.has(s.PropertyType)) return false;
            if (sf.commissionStatus === 'needs_finalization' && !(s.status === 'Approved' && !s.commission_amount)) return false;
            if (sf.commissionStatus === 'finalized'        && !s.commission_amount) return false;
            if (sf.commissionStatus === 'awaiting_payment' && !(s.status === 'Approved' && s.commission_amount && !['paid'].includes(s.commission_status))) return false;
            if (sf.commissionStatus === 'paid'             && !(s.status === 'Approved' && s.commission_status === 'paid')) return false;
            if (sf.saleDateFrom && s.sale_date && s.sale_date < sf.saleDateFrom) return false;
            if (sf.saleDateTo   && s.sale_date && s.sale_date > sf.saleDateTo)   return false;
            if (sf.city  && s.City !== sf.city) return false;
            if (sf.agent && String(s.agent_id) !== String(sf.agent)) return false;
            return true;
        });
        matches.sort((a, b) => {
            switch (sf.sort) {
                case 'oldest':     return new Date(a.submitted_at||0) - new Date(b.submitted_at||0);
                case 'price_high': return Number(b.sale_price) - Number(a.sale_price);
                case 'price_low':  return Number(a.sale_price) - Number(b.sale_price);
                case 'agent_az':   return (a.agent_first_name||'').localeCompare(b.agent_first_name||'');
                default:           return new Date(b.submitted_at||0) - new Date(a.submitted_at||0);
            }
        });
        return matches;
    }

    function sfApply() {
        if (!_cardMap) sfBuildCardMap();
        const matches = sfFilterAndSort();
        const matchIds = new Set(matches.map(s => s.verification_id));
        const grid = document.querySelector('.sales-grid');
        if (grid) {
            _cardMap.forEach((card, vid) => { card.style.display = matchIds.has(vid) ? '' : 'none'; });
            // Re-order DOM
            matches.forEach(s => { const c = _cardMap.get(s.verification_id); if (c) grid.appendChild(c); });
        }
        // Tab total
        const tabStatus = sfGetTabStatus();
        const total = tabStatus === 'All' ? saleVerifications.length : saleVerifications.filter(s => s.status === tabStatus).length;

        const sfNum = document.getElementById('sfResultsNum');
        if (sfNum) sfNum.textContent = matches.length;
        const n = sfCountActive();
        const badge = document.getElementById('filterCountBadge'); if (badge) { badge.textContent = n; badge.style.display = n > 0 ? 'inline-flex' : 'none'; }
        const pill = document.getElementById('sfActivePill'); if (pill) { pill.classList.toggle('show', n > 0); }
        const pillTxt = document.getElementById('sfActivePillText'); if (pillTxt) pillTxt.textContent = n + (n === 1 ? ' active' : ' active');
        const btn = document.getElementById('openFilterBtn'); if (btn) btn.classList.toggle('filter-active', n > 0);
        // No-results per grid
        const noR = document.getElementById('sfNoResults'); if (noR) noR.style.display = matches.length === 0 && total > 0 ? 'block' : 'none';
    }

    function sfPreview() { sfApply(); }

    function sfOpen() {
        sfBuildCardMap();
        document.getElementById('sfSidebar').classList.add('active');
        _lockScroll();
    }
    function sfClose() {
        document.getElementById('sfSidebar').classList.remove('active');
        _unlockScroll();
    }

    function sfReset() {
        document.getElementById('sfSearchInput') && (document.getElementById('sfSearchInput').value = '');
        document.getElementById('quickSearchInput') && (document.getElementById('quickSearchInput').value = '');
        const minSl = document.getElementById('sfPriceMinSlider'), maxSl = document.getElementById('sfPriceMaxSlider');
        if (minSl) minSl.value = 0;
        if (maxSl) maxSl.value = sf._maxPrice;
        sfUpdatePriceSlider();
        document.querySelectorAll('.sf-type-cb').forEach(cb => { cb.checked = true; cb.closest('.filter-chip')?.classList.add('active'); });
        document.querySelectorAll('[data-commission-status]').forEach(c => c.classList.toggle('active', c.dataset.commissionStatus === 'all'));
        document.getElementById('sfDateFrom') && (document.getElementById('sfDateFrom').value = '');
        document.getElementById('sfDateTo')   && (document.getElementById('sfDateTo').value = '');
        document.getElementById('sfCitySelect')  && (document.getElementById('sfCitySelect').value = '');
        document.getElementById('sfAgentSelect') && (document.getElementById('sfAgentSelect').value = '');
        document.querySelectorAll('.quick-filter-btn').forEach(b => b.classList.remove('active'));
        // Also clear commission section search
        cmClearSearch();
        sfApply();
    }

    document.addEventListener('DOMContentLoaded', sfInit);

    // ===== VIEW DETAILS (enhanced) =====
    function viewDetails(vid) {
        const sale = saleVerifications.find(s => s.verification_id == vid);
        if (!sale) return;
        currentViewedSale = sale;

        const stClass = sale.status.toLowerCase();
        const stLabel = sale.status === 'Approved' ? 'SOLD' : sale.status.toUpperCase();
        const imgs = (sale.property_images && sale.property_images.length > 0) ? sale.property_images : [];
        const totalImgs = imgs.length;

        // Update header badge
        const vidBadge = document.getElementById('modalVidBadge');
        if (vidBadge) vidBadge.textContent = 'VID #' + sale.verification_id;

        // ── Price variance ──
        const saleP = Number(sale.sale_price);
        const listP = Number(sale.ListingPrice);
        let varianceHtml = '';
        if (listP > 0) {
            const diff = ((saleP - listP) / listP * 100).toFixed(1);
            const cls  = diff > 0 ? 'up' : diff < 0 ? 'down' : 'flat';
            const icon = diff > 0 ? '▲' : diff < 0 ? '▼' : '—';
            varianceHtml = `<div class="svd-variance ${cls}">${icon} ${Math.abs(diff)}% vs listing</div>`;
        }

        let html = '';

        // ── HERO ──
        html += `<div class="svd-hero">`;
        if (totalImgs > 0) {
            html += imgs.map((img, i) => `<img src="${img.url}" alt="" class="svd-hero-img" id="svd-img-${i}" style="position:absolute;inset:0;opacity:${i===0?1:0};transition:opacity 0.4s;">`).join('');
            if (totalImgs > 1) {
                html += `<button class="svd-gallery-prev" id="svdPrev" onclick="svdGoPrev()"><i class="bi bi-chevron-left"></i></button>`;
                html += `<button class="svd-gallery-next" id="svdNext" onclick="svdGoNext()"><i class="bi bi-chevron-right"></i></button>`;
                html += `<div class="svd-hero-dots">${imgs.map((_,i) => `<button class="svd-hero-dot ${i===0?'active':''}" id="svd-dot-${i}" onclick="svdGoTo(${i})"></button>`).join('')}</div>`;
                html += `<div class="svd-gallery-counter" id="svdCounter" style="display:block;">${1} / ${totalImgs}</div>`;
            }
        } else {
            html += `<div class="svd-hero-no-img"><i class="bi bi-image"></i><span>No Images</span></div>`;
        }
        html += `<div class="svd-hero-overlay"></div>`;
        html += `<div class="svd-hero-top">
            <span class="svd-type-badge"><i class="bi bi-house-door me-1"></i>${esc(sale.PropertyType)}</span>
            <span class="svd-status-hero ${stClass}">${stLabel}</span>
        </div>`;
        html += `<div class="svd-hero-content">
            <div class="svd-hero-address">${esc(sale.StreetAddress)}</div>
            <div class="svd-hero-city"><i class="bi bi-geo-alt-fill" style="color:var(--gold-light);"></i>${esc(sale.City)}</div>
        </div>`;
        html += `</div>`;

        // ── STAT STRIP ──
        html += `<div class="svd-stat-strip">
            <div class="svd-stat">
                <div class="svd-stat-label">Sale Price</div>
                <div class="svd-stat-value gold">₱${saleP.toLocaleString()}</div>
                ${varianceHtml}
            </div>
            <div class="svd-stat">
                <div class="svd-stat-label">Listing Price</div>
                <div class="svd-stat-value">₱${listP.toLocaleString()}</div>
                <div class="svd-stat-sub">Original ask</div>
            </div>
            <div class="svd-stat">
                <div class="svd-stat-label">Sale Date</div>
                <div class="svd-stat-value">${sale.sale_date_fmt || '—'}</div>
                <div class="svd-stat-sub">Closing date</div>
            </div>
            <div class="svd-stat">
                <div class="svd-stat-label">Status</div>
                <div class="svd-stat-value" style="margin-top:0.15rem;"><span class="svd-status-pill ${stClass}"><span class="svd-dot ${stClass}"></span>${stLabel}</span></div>
            </div>
        </div>`;

        // ── BODY ──
        html += `<div class="svd-body">`;

        // Buyer + Agent two-col
        html += `<div class="svd-section">
            <div class="svd-section-title"><i class="bi bi-people-fill"></i> Parties Involved</div>
            <div class="svd-two-col">
                <div class="svd-panel">
                    <div class="svd-panel-title buyer"><i class="bi bi-person-fill"></i> Buyer</div>
                    <div class="svd-row"><span class="svd-row-icon"><i class="bi bi-person"></i></span><span class="svd-row-label">Name</span><span class="svd-row-value strong">${esc(sale.buyer_name)}</span></div>
                    ${sale.buyer_email
                        ? `<div class="svd-row"><span class="svd-row-icon"><i class="bi bi-envelope"></i></span><span class="svd-row-label">Email</span><span class="svd-row-value"><a href="mailto:${esc(sale.buyer_email)}" class="svd-email-link">${esc(sale.buyer_email)}</a></span></div>`
                        : `<div class="svd-row"><span class="svd-row-icon"><i class="bi bi-envelope"></i></span><span class="svd-row-label">Email</span><span class="svd-row-value" style="color:var(--text-secondary);font-style:italic;">Not provided</span></div>`
                    }
                </div>
                <div class="svd-panel">
                    <div class="svd-panel-title blue"><i class="bi bi-person-badge-fill"></i> Agent</div>
                    <div class="svd-row"><span class="svd-row-icon"><i class="bi bi-person-check"></i></span><span class="svd-row-label">Name</span><span class="svd-row-value strong">${esc(sale.agent_first_name)} ${esc(sale.agent_last_name)}</span></div>
                    <div class="svd-row"><span class="svd-row-icon"><i class="bi bi-envelope"></i></span><span class="svd-row-label">Email</span><span class="svd-row-value"><a href="mailto:${esc(sale.agent_email)}" class="svd-email-link">${esc(sale.agent_email)}</a></span></div>
                </div>
            </div>
        </div>`;

        // Property info grid
        html += `<div class="svd-section">
            <div class="svd-section-title"><i class="bi bi-building"></i> Property Details</div>
            <div class="svd-detail-grid">
                <div class="svd-detail-cell"><div class="cell-label">Type</div><div class="cell-value">${esc(sale.PropertyType)}</div></div>
                <div class="svd-detail-cell"><div class="cell-label">City</div><div class="cell-value">${esc(sale.City)}</div></div>
                <div class="svd-detail-cell"><div class="cell-label">Listing Price</div><div class="cell-value gold">₱${listP.toLocaleString()}</div></div>
                <div class="svd-detail-cell"><div class="cell-label">Property ID</div><div class="cell-value muted">#${sale.property_id}</div></div>
            </div>
        </div>`;

        // Additional notes
        if (sale.additional_notes) {
            html += `<div class="svd-section">
                <div class="svd-section-title"><i class="bi bi-chat-square-text"></i> Additional Notes</div>
                <div class="svd-notes-box"><div class="notes-title"><i class="bi bi-info-circle"></i> Notes</div><div class="notes-text">${esc(sale.additional_notes)}</div></div>
            </div>`;
        }

        // Rejection reason
        if (sale.admin_notes) {
            html += `<div class="svd-section">
                <div class="svd-section-title"><i class="bi bi-x-circle"></i> Rejection Details</div>
                <div class="svd-rejection-box"><div class="rej-title"><i class="bi bi-exclamation-triangle-fill"></i> Reason for Rejection</div><div class="rej-text">${esc(sale.admin_notes)}</div></div>
            </div>`;
        }

        // Commission
        if (sale.commission_amount && Number(sale.commission_amount) > 0) {
            const commStatusClass = (sale.commission_status || 'pending').toLowerCase();
            const methodLabels = {bank_transfer:'Bank Transfer',gcash:'GCash',maya:'Maya',cash:'Cash',check:'Check',other:'Other'};
            let commExtra = '';
            if (sale.commission_status === 'paid' && sale.commission_paid_at) {
                const paidDate = new Date(sale.commission_paid_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
                const paidByName = (sale.paid_by_first && sale.paid_by_last) ? esc(sale.paid_by_first) + ' ' + esc(sale.paid_by_last) : 'Admin';
                commExtra += `<div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid rgba(22,163,74,0.1);">`;
                commExtra += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem 1rem;">`;
                if (sale.commission_payment_method) {
                    commExtra += `<div><span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-secondary);font-weight:600;">Payment Method</span><div style="font-weight:600;color:var(--text-primary);font-size:0.85rem;margin-top:2px;">${esc(methodLabels[sale.commission_payment_method] || sale.commission_payment_method)}</div></div>`;
                }
                if (sale.commission_payment_ref) {
                    commExtra += `<div><span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-secondary);font-weight:600;">Reference</span><div style="font-weight:600;color:var(--text-primary);font-size:0.85rem;margin-top:2px;font-family:monospace;">${esc(sale.commission_payment_ref)}</div></div>`;
                }
                commExtra += `<div><span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-secondary);font-weight:600;">Paid On</span><div style="font-weight:600;color:#16a34a;font-size:0.85rem;margin-top:2px;">${paidDate}</div></div>`;
                commExtra += `<div><span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-secondary);font-weight:600;">Paid By</span><div style="font-weight:600;color:var(--text-primary);font-size:0.85rem;margin-top:2px;">${paidByName}</div></div>`;
                commExtra += `</div>`;
                if (sale.commission_proof_path) {
                    commExtra += `<div style="margin-top:0.6rem;"><a href="download_commission_proof.php?id=${sale.commission_id}" class="svd-email-link" style="font-size:0.8rem;display:inline-flex;align-items:center;gap:0.3rem;"><i class="bi bi-file-earmark-arrow-down"></i> Download Payment Proof</a></div>`;
                }
                if (sale.commission_payment_notes) {
                    commExtra += `<div style="margin-top:0.5rem;background:rgba(0,0,0,0.02);border-radius:4px;padding:0.5rem 0.65rem;font-size:0.8rem;color:var(--text-secondary);"><i class="bi bi-chat-left-text" style="margin-right:0.3rem;color:#16a34a;"></i>${esc(sale.commission_payment_notes)}</div>`;
                }
                commExtra += `</div>`;
            }
            html += `<div class="svd-section">
                <div class="svd-section-title"><i class="bi bi-cash-coin"></i> Commission</div>
                <div class="svd-commission-panel">
                    <div>
                        <div class="cp-label"><i class="bi bi-check-circle-fill"></i> Commission Earned</div>
                        <div class="cp-value">₱${Number(sale.commission_amount).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
                        <div class="cp-pct">${Number(sale.commission_percentage)}% of ₱${saleP.toLocaleString()}</div>
                    </div>
                    <span class="svd-commission-badge ${commStatusClass}" style="text-transform:uppercase;">${esc(sale.commission_status || 'Pending')}</span>
                </div>
                ${commExtra}
            </div>`;
        }

        // Documents
        if (sale.documents && sale.documents.length > 0) {
            html += `<div class="svd-section">
                <div class="svd-section-title"><i class="bi bi-file-earmark-text"></i> Supporting Documents <span style="font-weight:500;color:var(--text-secondary);font-size:0.75rem;text-transform:none;letter-spacing:0;margin-left:0.35rem;">(${sale.document_count})</span></div>
                <div class="svd-doc-list">
                    ${sale.documents.map(doc => {
                        const ext = (doc.original_filename || '').split('.').pop().toLowerCase();
                        const isImg = ['jpg','jpeg','png','gif','webp'].includes(ext);
                        const isPdf = ext === 'pdf';
                        const isWord = ['doc','docx'].includes(ext);
                        const iconClass = isPdf ? 'bi-file-pdf' : isImg ? 'bi-file-image' : isWord ? 'bi-file-word' : 'bi-file-earmark';
                        const wrapClass = isPdf ? 'pdf' : isImg ? 'img' : isWord ? 'word' : 'file';
                        const canPreview = isImg || isPdf;
                        return `<div class="svd-doc-item">
                            <div class="svd-doc-icon-wrap ${wrapClass}"><i class="bi ${iconClass}"></i></div>
                            <div class="svd-doc-info">
                                <div class="svd-doc-name" title="${esc(doc.original_filename)}">${esc(doc.original_filename)}</div>
                                <div class="svd-doc-meta">${formatSize(doc.file_size)} &bull; Uploaded ${new Date(doc.uploaded_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</div>
                            </div>
                            <div class="svd-doc-actions">
                                ${canPreview ? `<button class="svd-btn-doc preview" onclick="previewDoc('${doc.file_path}','${doc.mime_type}','${esc(doc.original_filename)}',${doc.id})" title="Preview"><i class="bi bi-eye"></i></button>` : ''}
                                <button class="svd-btn-doc download" onclick="downloadDoc(${doc.id})" title="Download"><i class="bi bi-download"></i></button>
                            </div>
                        </div>`;
                    }).join('')}
                </div>
            </div>`;
        }

        // Timeline
        let tlHtml = `<div class="svd-section">
            <div class="svd-section-title"><i class="bi bi-clock-history"></i> Timeline</div>
            <div class="svd-timeline">
                <div class="svd-tl-item"><div class="svd-tl-dot gold"><i class="bi bi-upload"></i></div><div class="svd-tl-content"><div class="tl-event">Verification Submitted</div><div class="tl-time">${sale.submitted_at_fmt || '—'}</div></div></div>`;
        if (sale.reviewed_at) {
            const tlDotCls = stClass === 'approved' ? 'green' : 'red';
            const tlIcon   = stClass === 'approved' ? 'bi-check-lg' : 'bi-x-lg';
            tlHtml += `<div class="svd-tl-item"><div class="svd-tl-dot ${tlDotCls}"><i class="bi ${tlIcon}"></i></div><div class="svd-tl-content"><div class="tl-event">Verification ${sale.status}</div><div class="tl-time">${sale.reviewed_at_fmt}</div></div></div>`;
        } else {
            tlHtml += `<div class="svd-tl-item"><div class="svd-tl-dot gray"><i class="bi bi-hourglass-split"></i></div><div class="svd-tl-content"><div class="tl-event">Awaiting Review</div><div class="tl-time">Pending admin decision</div></div></div>`;
        }
        tlHtml += `</div></div>`;
        html += tlHtml;

        html += `</div>`; // close svd-body

        document.getElementById('modalContent').innerHTML = html;

        // Init hero gallery state
        svdGalleryInit(totalImgs);

        // Footer
        let footer = '';
        if (sale.status === 'Pending') {
            footer = `<button class="btn-modal btn-modal-success" onclick="approveFromModal(${vid})"><i class="bi bi-check-lg"></i>Approve</button>
                      <button class="btn-modal btn-modal-danger" onclick="rejectFromModal(${vid})"><i class="bi bi-x-lg"></i>Reject</button>
                      <button class="btn-modal btn-modal-secondary" onclick="closeModal('detailsModal')"><i class="bi bi-x-lg"></i>Close</button>`;
        } else if (sale.status === 'Approved') {
            const hasCommission = sale.commission_amount && Number(sale.commission_amount) > 0;
            const commStatus = (sale.commission_status || '').toLowerCase();
            const isPaid = commStatus === 'paid';
            const canPay = hasCommission && (commStatus === 'calculated' || commStatus === 'processing');
            footer = `<button class="btn-modal btn-modal-secondary" onclick="closeModal('detailsModal')"><i class="bi bi-x-lg"></i>Close</button>`;
            if (!isPaid) {
                footer += `<button class="btn-modal btn-modal-primary" onclick="openFinalizeModal()"><i class="bi bi-cash-coin"></i>${hasCommission ? 'Edit' : 'Finalize'} Commission</button>`;
            }
            if (canPay) {
                footer += `<button class="btn-modal btn-modal-success" onclick="openPaymentModal()"><i class="bi bi-wallet2"></i> Process Payment</button>`;
            }
            if (isPaid) {
                footer += `<span style="display:inline-flex;align-items:center;gap:0.35rem;color:#16a34a;font-weight:700;font-size:0.85rem;padding:0.5rem 1rem;background:rgba(22,163,74,0.06);border-radius:4px;border:1px solid rgba(22,163,74,0.15);"><i class="bi bi-check-circle-fill"></i> Commission Paid</span>`;
            }
        } else {
            footer = `<button class="btn-modal btn-modal-secondary" onclick="closeModal('detailsModal')"><i class="bi bi-x-lg"></i>Close</button>`;
        }
        document.getElementById('modalFooter').innerHTML = footer;

        openModal('detailsModal');
    }

    // ===== SVD HERO GALLERY =====
    let svdGalIdx = 0, svdGalTotal = 0;
    function svdGalleryInit(total) {
        svdGalIdx = 0;
        svdGalTotal = total;
        svdUpdateGallery();
    }
    function svdUpdateGallery() {
        for (let i = 0; i < svdGalTotal; i++) {
            const img = document.getElementById('svd-img-' + i);
            const dot = document.getElementById('svd-dot-' + i);
            if (img) img.style.opacity = (i === svdGalIdx) ? '1' : '0';
            if (dot) dot.classList.toggle('active', i === svdGalIdx);
        }
        const counter = document.getElementById('svdCounter');
        if (counter) counter.textContent = (svdGalIdx + 1) + ' / ' + svdGalTotal;
        const prev = document.getElementById('svdPrev');
        const next = document.getElementById('svdNext');
        if (prev) prev.style.opacity = svdGalIdx === 0 ? '0.4' : '1';
        if (next) next.style.opacity = svdGalIdx >= svdGalTotal - 1 ? '0.4' : '1';
    }
    function svdGoNext() { if (svdGalIdx < svdGalTotal - 1) { svdGalIdx++; svdUpdateGallery(); } }
    function svdGoPrev() { if (svdGalIdx > 0) { svdGalIdx--; svdUpdateGallery(); } }
    function svdGoTo(i) { svdGalIdx = i; svdUpdateGallery(); }

    // ===== APPROVE / REJECT =====
    function approveVerification(vid) { approveFromModal(vid); }
    function rejectVerification(vid) { rejectFromModal(vid); }

    function approveFromModal(vid) {
        document.getElementById('cfdTopBar').className  = 'cfd-top-bar approve';
        document.getElementById('cfdIconWrap').className = 'cfd-icon-wrap approve';
        document.getElementById('cfdIconWrap').innerHTML = '<i class="bi bi-patch-check-fill"></i>';
        document.getElementById('cfdTitle').textContent  = 'Approve Sale';
        document.getElementById('cfdDesc').innerHTML     = 'Are you sure you want to approve this sale? The property will be marked as <strong>SOLD</strong> and both the agent and buyer will be notified by email.';
        const btn = document.getElementById('confirmActionBtn');
        const newBtn = btn.cloneNode(true);
        newBtn.className = 'cfd-btn cfd-approve';
        newBtn.innerHTML = '<i class="bi bi-check-lg"></i> Approve';
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener('click', () => {
            closeModal('confirmModal');
            submitAction('approve', vid);
        });
        openModal('confirmModal');
    }

    function rejectFromModal(vid) {
        document.getElementById('reasonInput').value = '';
        document.getElementById('reasonError').style.display = 'none';
        const btn = document.getElementById('submitRejectBtn');
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener('click', () => {
            const reason = document.getElementById('reasonInput').value.trim();
            if (!reason) { document.getElementById('reasonError').style.display = 'block'; return; }
            closeModal('reasonModal');
            submitAction('reject', vid, reason);
        });
        openModal('reasonModal');
    }

    function submitAction(action, vid, reason) {
        const form = document.createElement('form');
        form.method = 'POST'; form.action = window.location.pathname;
        form.innerHTML = `<input type="hidden" name="action" value="${action}"><input type="hidden" name="verification_id" value="${vid}">`;
        if (reason) form.innerHTML += `<input type="hidden" name="reason" value="${esc(reason)}">`;
        document.body.appendChild(form);
        showProcessing(action === 'approve' ? 'Approving sale...' : 'Rejecting sale...', action);
        form.submit();
    }

    // ===== DOCUMENTS =====
    function previewDoc(path, mime, name, id) {
        const webPath = path.replace(/^\.\.\/sale_documents\//, 'sale_documents/');
        currentDocId = id; currentDocName = name;
        document.getElementById('previewTitle').innerHTML = `<i class="bi bi-file-earmark-text"></i> ${esc(name)}`;
        const c = document.getElementById('previewContent');
        if (mime.startsWith('image/')) {
            c.innerHTML = `<div style="text-align:center;"><img src="${webPath}" alt="${esc(name)}" style="max-width:100%;max-height:70vh;object-fit:contain;border-radius:4px;"></div>`;
        } else if (mime === 'application/pdf') {
            c.innerHTML = `<div style="height:70vh;"><iframe src="${webPath}" width="100%" height="100%" style="border:none;border-radius:4px;"></iframe></div>`;
        } else {
            c.innerHTML = `<div style="text-align:center;padding:3rem;"><i class="bi bi-file-earmark" style="font-size:3rem;color:var(--text-secondary);"></i><p style="margin-top:1rem;color:var(--text-secondary);">Preview not available. Click Download to view.</p></div>`;
        }
        openModal('previewModal');
    }
    function downloadDoc(id) { window.location.href = 'download_document.php?id=' + id; }
    function downloadCurrentDocument() { if (currentDocId) downloadDoc(currentDocId); }

    // ===== FINALIZE COMMISSION =====
    let finalizeModalInstance = null;
    document.addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById('finalizeSaleModal');
        if (el && window.bootstrap) finalizeModalInstance = new bootstrap.Modal(el);
    });

    function openFinalizeModal() {
        const sale = currentViewedSale;
        if (!sale) return;
        document.getElementById('finalize_property_id').value = sale.property_id || '';
        document.getElementById('finalize_agent_id').value = sale.agent_id || '';
        document.getElementById('final_sale_price').value = sale.sale_price || '';
        document.getElementById('buyer_name').value = sale.buyer_name || '';
        document.getElementById('buyer_email').value = sale.finalized_buyer_email || sale.buyer_email || '';
        document.getElementById('commission_percentage').value = sale.commission_percentage || '';
        document.getElementById('notes').value = '';
        document.getElementById('finalizeHelp').innerHTML =
            `Property <strong>#${sale.property_id}</strong> &bull; Agent: <strong>${esc(sale.agent_first_name)} ${esc(sale.agent_last_name)}</strong>`;
        fsmCalc();
        if (finalizeModalInstance) finalizeModalInstance.show();
    }

    function fsmCalc() {
        const price = parseFloat(document.getElementById('final_sale_price')?.value) || 0;
        const pct   = parseFloat(document.getElementById('commission_percentage')?.value);
        const valEl = document.getElementById('fsmCommVal');
        if (!valEl) return;
        if (price > 0 && !isNaN(pct) && pct >= 0 && pct <= 100) {
            const comm = price * pct / 100;
            valEl.textContent = '\u20b1' + comm.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            valEl.classList.remove('fsm-dim');
        } else {
            valEl.innerHTML = '&mdash;';
            valEl.classList.add('fsm-dim');
        }
    }

    const ff = document.getElementById('finalizeSaleForm');
    if (ff) ff.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(ff);
        const price = parseFloat(fd.get('final_sale_price'));
        const pct = parseFloat(fd.get('commission_percentage'));
        if (!price || price <= 0) {
            showToast('error', 'Invalid Sale Price', 'Please enter a valid sale price greater than zero.');
            return;
        }
        if (isNaN(pct) || pct < 0 || pct > 100) {
            showToast('error', 'Invalid Commission Rate', 'Commission rate must be between 0 and 100.');
            return;
        }
        showProcessing('Finalizing sale...', 'finalize');
        try {
            const res = await fetch('admin_finalize_sale.php', { method: 'POST', body: fd });
            const data = await res.json();
            hideProcessing();
            if (data.ok) {
                if (finalizeModalInstance) finalizeModalInstance.hide();
                const formatted = '&#x20b1;' + Number(data.commission_amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                const emailNote = data.email_sent ? '<br><small style="opacity:.75;"><i class="bi bi-envelope-check"></i> Agent notified via email</small>' : '';
                showToast('success', 'Commission Saved', 'Sale finalized successfully. Commission calculated: <strong>' + formatted + '</strong>' + emailNote, 5500);
                setTimeout(() => { location.href = location.pathname + '?success=finalized'; }, 1800);
            } else {
                showToast('error', 'Finalization Failed', data.message || 'Failed to finalize the sale. Please try again.');
            }
        } catch (err) {
            hideProcessing();
            showToast('error', 'Network Error', 'An unexpected error occurred. Please check your connection and try again.');
            console.error(err);
        }
    });

    // ===== PROCESS COMMISSION PAYMENT =====
    let paymentModalInstance = null;
    document.addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById('processPaymentModal');
        if (el && window.bootstrap) paymentModalInstance = new bootstrap.Modal(el);

        // File upload interactions
        const zone = document.getElementById('ppmUploadZone');
        const input = document.getElementById('ppm_payment_proof');
        const content = document.getElementById('ppmUploadContent');
        const preview = document.getElementById('ppmUploadPreview');
        const removeBtn = document.getElementById('ppmRemoveFile');

        if (zone && input) {
            // Drag & drop visual feedback
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
            zone.addEventListener('drop', e => {
                e.preventDefault();
                zone.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    input.files = e.dataTransfer.files;
                    ppmShowFilePreview(e.dataTransfer.files[0]);
                }
            });

            input.addEventListener('change', () => {
                if (input.files.length > 0) {
                    ppmShowFilePreview(input.files[0]);
                }
            });

            if (removeBtn) {
                removeBtn.addEventListener('click', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    input.value = '';
                    if (content) content.style.display = '';
                    if (preview) preview.style.display = 'none';
                });
            }
        }
    });

    function ppmShowFilePreview(file) {
        const content = document.getElementById('ppmUploadContent');
        const preview = document.getElementById('ppmUploadPreview');
        const nameEl = document.getElementById('ppmPreviewName');
        const sizeEl = document.getElementById('ppmPreviewSize');
        if (!content || !preview) return;

        content.style.display = 'none';
        preview.style.display = 'flex';
        if (nameEl) nameEl.textContent = file.name;
        if (sizeEl) sizeEl.textContent = formatSize(file.size);
    }

    function openPaymentModal() {
        const sale = currentViewedSale;
        if (!sale || !sale.commission_id) {
            showToast('error', 'Error', 'No commission found for this sale. Please finalize the commission first.');
            return;
        }
        if (sale.commission_status === 'paid') {
            showToast('info', 'Already Paid', 'This commission has already been paid.');
            return;
        }

        // Reset form
        const form = document.getElementById('processPaymentForm');
        if (form) form.reset();
        const content = document.getElementById('ppmUploadContent');
        const preview = document.getElementById('ppmUploadPreview');
        if (content) content.style.display = '';
        if (preview) preview.style.display = 'none';

        // Populate data
        document.getElementById('ppm_commission_id').value = sale.commission_id;
        document.getElementById('ppmCommAmount').textContent =
            '₱' + Number(sale.commission_amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        document.getElementById('ppmStatusBadge').textContent = (sale.commission_status || 'calculated').toUpperCase();
        document.getElementById('ppmHelp').innerHTML =
            `Commission <strong>#${sale.commission_id}</strong> &bull; Agent: <strong>${esc(sale.agent_first_name)} ${esc(sale.agent_last_name)}</strong>`;

        // Enable submit button
        const submitBtn = document.getElementById('ppmSubmitBtn');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Confirm Payment'; }

        if (paymentModalInstance) paymentModalInstance.show();
    }

    const pf = document.getElementById('processPaymentForm');
    if (pf) pf.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(pf);

        // Client-side validation
        const method = fd.get('payment_method');
        const ref = (fd.get('payment_reference') || '').trim();
        const proof = fd.get('payment_proof');

        if (!method) {
            showToast('error', 'Missing Field', 'Please select a payment method.');
            return;
        }
        if (!ref) {
            showToast('error', 'Missing Field', 'Transaction reference is required.');
            return;
        }
        if (!proof || proof.size === 0) {
            showToast('error', 'Missing File', 'Please upload payment proof.');
            return;
        }

        // Validate file client-side
        const maxSize = 5 * 1024 * 1024;
        if (proof.size > maxSize) {
            showToast('error', 'File Too Large', 'Payment proof must be 5 MB or smaller.');
            return;
        }
        const allowedExts = ['jpg','jpeg','png','webp','gif','pdf'];
        const ext = proof.name.split('.').pop().toLowerCase();
        if (!allowedExts.includes(ext)) {
            showToast('error', 'Invalid File', 'Allowed file types: JPG, PNG, WEBP, GIF, PDF.');
            return;
        }

        // Disable button
        const submitBtn = document.getElementById('ppmSubmitBtn');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="bi bi-arrow-repeat fa-spin"></i> Processing…'; }

        showProcessing('Processing payment...', 'payment');

        try {
            const res = await fetch('process_commission_payment.php', { method: 'POST', body: fd });
            const data = await res.json();
            hideProcessing();

            if (data.ok) {
                if (paymentModalInstance) paymentModalInstance.hide();
                const emailNote = data.email_sent ? '<br><small style="opacity:.75;"><i class="bi bi-envelope-check"></i> Agent notified via email</small>' : '';
                showToast('success', 'Payment Processed', 'Commission has been marked as <strong>PAID</strong> successfully.' + emailNote, 5500);
                setTimeout(() => { location.href = location.pathname + '?success=payment_processed'; }, 1800);
            } else {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Confirm Payment'; }
                showToast('error', 'Payment Failed', data.message || 'Failed to process payment. Please try again.');
            }
        } catch (err) {
            hideProcessing();
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Confirm Payment'; }
            showToast('error', 'Network Error', 'An unexpected error occurred. Please check your connection and try again.');
            console.error(err);
        }
    });

    // ===== COMMISSION MANAGEMENT TABLE =====
    function cmToggleTab(tab) {
        document.querySelectorAll('.cm-toggle-btn').forEach(b => b.classList.remove('active'));
        document.querySelector(`.cm-toggle-btn[data-cm-tab="${tab}"]`).classList.add('active');
        document.getElementById('cmTableAwaiting').style.display = tab === 'awaiting' ? '' : 'none';
        document.getElementById('cmTablePaid').style.display = tab === 'paid' ? '' : 'none';
        // Re-apply search when switching tabs
        cmSearch();
    }

    function cmSearch() {
        const input = document.getElementById('cmSearchInput');
        const clearBtn = document.getElementById('cmSearchClear');
        const q = (input ? input.value : '').toLowerCase().trim();
        if (clearBtn) clearBtn.style.display = q ? 'flex' : 'none';

        // Determine which table is visible
        const activeTab = document.querySelector('.cm-toggle-btn.active')?.dataset.cmTab || 'awaiting';
        const tableId = activeTab === 'paid' ? 'cmTablePaid' : 'cmTableAwaiting';
        const tableWrap = document.getElementById(tableId);
        if (!tableWrap) return;

        const rows = tableWrap.querySelectorAll('tbody tr');
        let visibleCount = 0;

        rows.forEach(row => {
            if (!q) {
                row.style.display = '';
                visibleCount++;
                return;
            }
            // Search across all text content in the row
            const rowText = row.innerText.toLowerCase();
            const match = rowText.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });

        // Show/hide no-results message
        let noRes = tableWrap.querySelector('.cm-no-results');
        if (rows.length > 0 && visibleCount === 0 && q) {
            if (!noRes) {
                noRes = document.createElement('div');
                noRes.className = 'cm-no-results';
                noRes.innerHTML = `<i class="bi bi-search"></i>No commissions match <strong>&ldquo;${esc(input.value)}&rdquo;</strong>. <a href="#" onclick="cmClearSearch();return false;" style="color:#16a34a;font-weight:600;">Clear search</a>`;
                tableWrap.appendChild(noRes);
            } else {
                noRes.innerHTML = `<i class="bi bi-search"></i>No commissions match <strong>&ldquo;${esc(input.value)}&rdquo;</strong>. <a href="#" onclick="cmClearSearch();return false;" style="color:#16a34a;font-weight:600;">Clear search</a>`;
                noRes.style.display = '';
            }
        } else if (noRes) {
            noRes.style.display = 'none';
        }
    }

    function cmClearSearch() {
        const input = document.getElementById('cmSearchInput');
        if (input) input.value = '';
        const clearBtn = document.getElementById('cmSearchClear');
        if (clearBtn) clearBtn.style.display = 'none';
        cmSearch();
    }

    function cmProcessPayment(verificationId) {
        // Find the sale in our data to set currentViewedSale, then open the payment modal
        const sale = saleVerifications.find(s => s.verification_id == verificationId);
        if (!sale) {
            showToast('error', 'Error', 'Could not find the sale verification data. Please refresh the page.');
            return;
        }
        if (!sale.commission_id) {
            showToast('error', 'Not Finalized', 'This sale has not been finalized yet. Commission must be calculated first.');
            return;
        }
        if (sale.commission_status === 'paid') {
            showToast('info', 'Already Paid', 'This commission has already been paid. No action needed.');
            return;
        }
        // Set global reference and open the existing payment modal
        currentViewedSale = sale;
        openPaymentModal();
    }

    // ===== UTILITY =====
    function esc(s) { if (!s) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function formatSize(b) {
        if (!b) return '0 B';
        const k = 1024, s = ['B','KB','MB','GB'];
        const i = Math.floor(Math.log(b) / Math.log(k));
        return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + s[i];
    }
    </script>

    <!-- ════════════════════════════════════════════════════════════════
         SKELETON HYDRATION SCRIPT — Progressive Content Reveal
         ----------------------------------------------------------------
         Trigger   : window 'load' (waits for ALL CSS, fonts, images,
                     JS to finish) + a configurable MIN_SKELETON_MS
                     minimum display so it never just flashes.
         What      : 1. Waits for window load + minimum display time.
                     2. Makes #page-content visible (display:block).
                     3. Fades #sk-screen out via opacity transition.
                     4. Simultaneously fades #page-content in.
                     5. Removes #sk-screen from DOM to free memory.
                     6. Dispatches 'skeleton:hydrated' custom event
                        so toasts and other deferred UI can fire.
         No-JS     : Handled by <noscript> CSS above — skeleton is
                     hidden and real content shown automatically.
         ════════════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        'use strict';

        /* ── Configuration ────────────────────────────────────────── */
        var MIN_SKELETON_MS = 400;   /* Minimum time skeleton stays visible (ms).
                                        Prevents a jarring flash on fast local loads.
                                        Increase to 600–800 for extra-smooth feel. */

        /* Record when the skeleton first rendered (approx. page navigation start) */
        var skeletonStart = Date.now();

        /**
         * hydrate()
         * Cross-fades skeleton out → real content in, then removes
         * the skeleton from the DOM and dispatches 'skeleton:hydrated'
         * so other code (toasts, etc.) can safely fire.
         */
        function hydrate() {
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');

            /* Safety: if elements are missing, just reveal content */
            if (!pc) return;
            if (!sk) {
                pc.style.cssText = 'display:block;opacity:1;';
                document.dispatchEvent(new Event('skeleton:hydrated'));
                return;
            }

            /* ── Step 1: Make real content visible but transparent ── */
            pc.style.display  = 'block';
            pc.style.opacity  = '0';

            /* ── Step 2: Animate on next frame ── */
            requestAnimationFrame(function () {

                /* Fade skeleton OUT */
                sk.style.transition = 'opacity 0.35s ease';
                sk.style.opacity    = '0';

                /* Fade real content IN (slight stagger) */
                pc.style.transition = 'opacity 0.42s ease 0.1s';
                requestAnimationFrame(function () {
                    pc.style.opacity = '1';
                });
            });

            /* ── Step 3: Remove skeleton & dispatch event after transition ── */
            window.setTimeout(function () {
                if (sk && sk.parentNode) {
                    sk.parentNode.removeChild(sk);
                }
                pc.style.transition = '';
                pc.style.opacity    = '';

                /* Signal that hydration is complete — toasts etc. can now fire */
                document.dispatchEvent(new Event('skeleton:hydrated'));
            }, 520);
        }

        /**
         * scheduleHydration()
         * Called when all resources are loaded (window 'load').
         * Enforces the minimum display time so the skeleton doesn't
         * just flash for 50 ms on fast connections.
         */
        function scheduleHydration() {
            var elapsed   = Date.now() - skeletonStart;
            var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);

            if (remaining > 0) {
                window.setTimeout(hydrate, remaining);
            } else {
                hydrate();
            }
        }

        /*
         * Trigger: window 'load' — fires only after ALL sub-resources
         * (CSS, fonts, images, JS) have finished loading.  This keeps
         * the skeleton visible the entire time external assets load,
         * which is the main source of perceived delay.
         *
         * Fallback: if 'load' already fired (shouldn't happen for an
         * inline script, but just in case), hydrate immediately.
         */
        if (document.readyState === 'complete') {
            scheduleHydration();
        } else {
            window.addEventListener('load', scheduleHydration);
        }

    }());
    </script>
</body>
</html>
