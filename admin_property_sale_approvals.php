<?php
session_start();
require_once 'connection.php';
require_once 'mail_helper.php';

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
        if (!empty($v['buyer_contact']) && filter_var($v['buyer_contact'], FILTER_VALIDATE_EMAIL)) {
            $buyerEmail = $v['buyer_contact'];
        } else {
            $tr = $conn->prepare("SELECT user_email FROM tour_requests WHERE property_id=? ORDER BY requested_at DESC LIMIT 1");
            $tr->bind_param('i', $property_id); $tr->execute();
            $row = $tr->get_result()->fetch_assoc(); $tr->close();
            if ($row) $buyerEmail = $row['user_email'] ?? null;
        }

        // 4) Create finalized_sales record (commission handled separately in finalize step)
        $ins = $conn->prepare("INSERT INTO finalized_sales
            (verification_id, property_id, agent_id, buyer_name, buyer_email, buyer_contact, final_sale_price, sale_date, additional_notes, finalized_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $ins->bind_param('iiisssdss' . 'i',
            $verification_id, $property_id, $v['agent_id'],
            $v['buyer_name'], $buyerEmail, $v['buyer_contact'],
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
        case 'approved':  $success_message = 'Sale verification approved! Property marked as SOLD.'; break;
        case 'rejected':  $success_message = 'Sale verification rejected successfully.'; break;
        case 'finalized': $success_message = 'Sale finalized and commission calculated.'; break;
    }
}

// ===== EMAIL BUILDER FUNCTIONS =====
function buildApprovalEmailAgent($name, $address, $type, $price, $date, $buyer) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;background:#0a0a0a;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a;padding:60px 20px;"><tr><td align="center"><table width="600" cellpadding="0" cellspacing="0" style="background:#111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
    <tr><td style="background:linear-gradient(90deg,#22c55e 0%,#16a34a 50%,#22c55e 100%);height:3px;"></td></tr>
    <tr><td style="padding:48px 48px 32px;text-align:center;border-bottom:1px solid #1f1f1f;"><h1 style="margin:0 0 12px;color:#22c55e;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Sale Approved</h1><p style="margin:0;color:#666;font-size:15px;">Congratulations on the successful sale!</p></td></tr>
    <tr><td style="padding:48px;">
        <p style="margin:0 0 24px;font-size:14px;color:#999;">Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($name) . '</span>,</p>
        <p style="margin:0 0 32px;font-size:15px;color:#ccc;line-height:1.8;">Your property sale verification has been <strong style="color:#22c55e;">approved</strong> by the admin team.</p>
        <div style="background:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 24px;">
            <p style="margin:0 0 12px;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Sale Details</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Property:</strong> ' . htmlspecialchars($address) . '</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Type:</strong> ' . htmlspecialchars($type) . '</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Sale Price:</strong> <span style="color:#22c55e;font-weight:700;">' . $price . '</span></p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Sale Date:</strong> ' . $date . '</p>
            <p style="margin:0;font-size:14px;color:#999;"><strong style="color:#ccc;">Buyer:</strong> ' . htmlspecialchars($buyer) . '</p>
        </div>
        <div style="background:#0d1117;border-left:2px solid #2563eb;padding:16px 20px;margin:0 0 24px;">
            <p style="margin:0;font-size:13px;color:#999;line-height:1.6;"><strong style="color:#2563eb;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">What\'s Next</strong>The property has been marked as SOLD. Commission processing will follow shortly. This sale will be reflected in your dashboard.</p>
        </div>
    </td></tr>
    <tr><td style="background:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;text-align:center;"><p style="margin:0 0 8px;font-size:13px;color:#666;"><strong style="color:#d4af37;">HomeEstate Realty</strong></p><p style="margin:0;font-size:11px;color:#444;">© ' . date('Y') . ' All rights reserved</p></td></tr>
    </table></td></tr></table></body></html>';
}

function buildApprovalEmailBuyer($name, $address, $type, $price, $date, $agent) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;background:#0a0a0a;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a;padding:60px 20px;"><tr><td align="center"><table width="600" cellpadding="0" cellspacing="0" style="background:#111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
    <tr><td style="background:linear-gradient(90deg,#d4af37 0%,#f4d03f 50%,#d4af37 100%);height:3px;"></td></tr>
    <tr><td style="padding:48px 48px 32px;text-align:center;border-bottom:1px solid #1f1f1f;"><h1 style="margin:0 0 12px;color:#d4af37;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Purchase Confirmed</h1><p style="margin:0;color:#666;font-size:15px;">Congratulations on your new property!</p></td></tr>
    <tr><td style="padding:48px;">
        <p style="margin:0 0 24px;font-size:14px;color:#999;">Dear <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($name) . '</span>,</p>
        <p style="margin:0 0 32px;font-size:15px;color:#ccc;line-height:1.8;">Your property purchase has been officially <strong style="color:#22c55e;">confirmed</strong>. Welcome to your new home!</p>
        <div style="background:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 24px;">
            <p style="margin:0 0 12px;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Property Details</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Address:</strong> ' . htmlspecialchars($address) . '</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Type:</strong> ' . htmlspecialchars($type) . '</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Purchase Price:</strong> <span style="color:#d4af37;font-weight:700;">' . $price . '</span></p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Sale Date:</strong> ' . $date . '</p>
            <p style="margin:0;font-size:14px;color:#999;"><strong style="color:#ccc;">Your Agent:</strong> ' . htmlspecialchars($agent) . '</p>
        </div>
        <div style="background:#0d1117;border-left:2px solid #22c55e;padding:16px 20px;margin:0 0 24px;">
            <p style="margin:0;font-size:13px;color:#999;line-height:1.6;"><strong style="color:#22c55e;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Next Steps</strong>Your agent will contact you to finalize documentation. Ensure all legal paperwork is completed. Schedule your property handover and key collection.</p>
        </div>
    </td></tr>
    <tr><td style="background:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;text-align:center;"><p style="margin:0 0 8px;font-size:13px;color:#666;"><strong style="color:#d4af37;">HomeEstate Realty</strong></p><p style="margin:0;font-size:11px;color:#444;">© ' . date('Y') . ' All rights reserved</p></td></tr>
    </table></td></tr></table></body></html>';
}

function buildRejectionEmailAgent($name, $address, $type, $price, $buyer, $reason) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;background:#0a0a0a;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a;padding:60px 20px;"><tr><td align="center"><table width="600" cellpadding="0" cellspacing="0" style="background:#111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
    <tr><td style="background:linear-gradient(90deg,#ef4444 0%,#dc2626 50%,#ef4444 100%);height:3px;"></td></tr>
    <tr><td style="padding:48px 48px 32px;text-align:center;border-bottom:1px solid #1f1f1f;"><h1 style="margin:0 0 12px;color:#ef4444;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Sale Rejected</h1><p style="margin:0;color:#666;font-size:15px;">Your sale verification requires attention</p></td></tr>
    <tr><td style="padding:48px;">
        <p style="margin:0 0 24px;font-size:14px;color:#999;">Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($name) . '</span>,</p>
        <p style="margin:0 0 32px;font-size:15px;color:#ccc;line-height:1.8;">Your sale verification has been <strong style="color:#ef4444;">rejected</strong>. Please review the details below.</p>
        <div style="background:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 24px;">
            <p style="margin:0 0 12px;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Submission Details</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Property:</strong> ' . htmlspecialchars($address) . '</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Type:</strong> ' . htmlspecialchars($type) . '</p>
            <p style="margin:0 0 8px;font-size:14px;color:#999;"><strong style="color:#ccc;">Sale Price:</strong> ' . $price . '</p>
            <p style="margin:0;font-size:14px;color:#999;"><strong style="color:#ccc;">Buyer:</strong> ' . htmlspecialchars($buyer) . '</p>
        </div>
        <div style="background:#0d1117;border-left:2px solid #ef4444;padding:20px 24px;margin:0 0 24px;">
            <p style="margin:0 0 8px;font-size:13px;color:#ef4444;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Rejection Reason</p>
            <p style="margin:0;font-size:14px;color:#ccc;line-height:1.7;">' . htmlspecialchars($reason) . '</p>
        </div>
        <div style="background:#0d1117;border-left:2px solid #2563eb;padding:16px 20px;margin:0 0 24px;">
            <p style="margin:0;font-size:13px;color:#999;line-height:1.6;"><strong style="color:#2563eb;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">What To Do</strong>Review the rejection reason, address the issues, gather correct documentation, and resubmit the sale verification with accurate details.</p>
        </div>
    </td></tr>
    <tr><td style="background:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;text-align:center;"><p style="margin:0 0 8px;font-size:13px;color:#666;"><strong style="color:#d4af37;">HomeEstate Realty</strong></p><p style="margin:0;font-size:11px;color:#444;">© ' . date('Y') . ' All rights reserved</p></td></tr>
    </table></td></tr></table></body></html>';
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
        ac.commission_amount, ac.commission_percentage, ac.status AS commission_status
    FROM sale_verifications sv
    LEFT JOIN property p ON p.property_ID = sv.property_id
    LEFT JOIN accounts a ON a.account_id = sv.agent_id
    LEFT JOIN finalized_sales fs ON fs.verification_id = sv.verification_id
    LEFT JOIN agent_commissions ac ON ac.sale_id = fs.sale_id
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ===== GLOBAL LAYOUT (matches property.php exactly) ===== */
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-light); color: #212529; }
        .admin-sidebar { background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%); color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; z-index: 1000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .admin-content { margin-left: 290px; padding: 2rem; min-height: 100vh; max-width: 1800px; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; padding: 1.5rem; } }
        @media (max-width: 768px)  { .admin-content { margin-left: 0 !important; padding: 1rem; } }

        .admin-content {
            --gold: #d4af37; --gold-light: #f4d03f; --gold-dark: #b8941f;
            --blue: #2563eb; --blue-light: #3b82f6; --blue-dark: #1e40af;
            --card-bg: #ffffff; --text-primary: #212529; --text-secondary: #6c757d;
        }

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

        /* ===== CONTENT AREA ===== */
        .tab-content { padding: 1.5rem; }
        .sales-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }

        /* ===== SALE CARD (consistent with property.php card style) ===== */
        .sale-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; transition: all 0.2s ease; height: 100%; display: flex; flex-direction: column; position: relative; }
        .sale-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, rgba(212,175,55,0.02), rgba(37,99,235,0.01)); opacity: 0; transition: opacity 0.2s; pointer-events: none; z-index: 1; }
        .sale-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .sale-card:hover::before { opacity: 1; }

        .card-img-wrap { position: relative; height: 200px; background: linear-gradient(135deg, #f8f9fa, #e9ecef); overflow: hidden; }
        .card-img-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; }
        .sale-card:hover .card-img-wrap img { transform: scale(1.03); }
        .card-img-wrap .img-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, transparent 40%, rgba(0,0,0,0.5)); pointer-events: none; }

        /* Badges on image */
        .card-img-wrap .type-badge { position: absolute; top: 10px; left: 10px; background: rgba(255,255,255,0.95); color: var(--text-primary); padding: 0.3rem 0.65rem; border-radius: 3px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; z-index: 2; backdrop-filter: blur(4px); border: 1px solid rgba(37,99,235,0.08); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .card-img-wrap .status-badge { position: absolute; top: 10px; right: 10px; padding: 0.3rem 0.7rem; border-radius: 3px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; }
        .status-badge.pending  { background: rgba(245,158,11,0.9); color: #fff; }
        .status-badge.approved { background: rgba(34,197,94,0.9);  color: #fff; }
        .status-badge.rejected { background: rgba(239,68,68,0.9);  color: #fff; }

        .card-img-wrap .price-overlay { position: absolute; bottom: 10px; left: 10px; z-index: 2; }
        .card-img-wrap .price-overlay .price { font-size: 1.2rem; font-weight: 800; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.3); }

        /* Card Body */
        .sale-card .card-body-content { padding: 1.25rem; flex: 1; display: flex; flex-direction: column; position: relative; z-index: 2; }
        .sale-card .prop-address { font-size: 1rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
        .sale-card .prop-location { font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.75rem; }
        .sale-card .prop-location i { color: var(--gold-dark); font-size: 0.7rem; }

        /* Info rows */
        .sale-info { background: rgba(37,99,235,0.03); padding: 0.75rem; border-radius: 3px; margin-bottom: 0.75rem; border: 1px solid rgba(37,99,235,0.06); }
        .sale-info-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .sale-info-row:last-child { margin-bottom: 0; }
        .sale-info-label { font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.3px; font-weight: 600; }
        .sale-info-value { font-size: 0.85rem; color: var(--text-primary); font-weight: 600; }
        .sale-info-value.sale-price { font-size: 0.95rem; color: var(--gold-dark); font-weight: 800; }

        /* People sections */
        .people-section { padding: 0.65rem 0.75rem; border-radius: 3px; margin-bottom: 0.5rem; border: 1px solid rgba(37,99,235,0.06); }
        .people-section.buyer { background: rgba(212,175,55,0.04); }
        .people-section.agent { background: rgba(37,99,235,0.03); }
        .people-section .section-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.35rem; }
        .people-section.buyer .section-title { color: var(--gold-dark); }
        .people-section.agent .section-title { color: var(--blue); }
        .people-section .detail-line { font-size: 0.8rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem; margin-bottom: 0.15rem; }
        .people-section .detail-line i { font-size: 0.7rem; width: 14px; text-align: center; }
        .people-section.buyer .detail-line i { color: var(--gold-dark); }
        .people-section.agent .detail-line i { color: var(--blue); }
        .doc-badge { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; color: var(--blue); font-weight: 600; margin-top: 0.25rem; }

        /* Card footer actions */
        .card-actions { display: flex; gap: 0.5rem; margin-top: auto; padding-top: 0.75rem; border-top: 1px solid rgba(37,99,235,0.08); }
        .btn-action { flex: 1; padding: 0.55rem; font-size: 0.8rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 0.35rem; }
        .btn-view { background: var(--text-primary); color: #fff; }
        .btn-view:hover { background: var(--gold); color: var(--text-primary); }
        .btn-approve { background: #22c55e; color: #fff; }
        .btn-approve:hover { background: #16a34a; }
        .btn-reject { background: #ef4444; color: #fff; }
        .btn-reject:hover { background: #dc2626; }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 4rem 2rem; background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; }
        .empty-state i { font-size: 3rem; color: var(--text-secondary); opacity: 0.3; margin-bottom: 0.75rem; display: block; }
        .empty-state h4 { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; }
        .empty-state p { color: var(--text-secondary); margin: 0; }

        /* ===== ALERTS ===== */
        .alert { border-radius: 4px; border-left: 3px solid; margin-bottom: 1rem; padding: 0.85rem 1.25rem; font-size: 0.9rem; }

        /* ===== MODAL (consistent admin light theme) ===== */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; z-index: 1050; opacity: 0; transition: opacity 0.2s ease; }
        .modal-overlay.show { display: flex; opacity: 1; align-items: center; justify-content: center; }
        .modal-container { background: var(--card-bg); border-radius: 4px; box-shadow: 0 8px 32px rgba(0,0,0,0.15); max-width: 800px; width: 92%; max-height: 92vh; overflow-y: auto; transform: scale(0.95); opacity: 0; transition: all 0.2s cubic-bezier(0.16,1,0.3,1); border: 1px solid rgba(37,99,235,0.1); }
        .modal-large { max-width: 1100px; width: 95%; }
        .modal-overlay.show .modal-container { opacity: 1; transform: scale(1); }

        .modal-admin-header { background: var(--card-bg); padding: 1.25rem 1.75rem; border-bottom: 1px solid rgba(37,99,235,0.1); display: flex; align-items: center; justify-content: space-between; position: relative; }
        .modal-admin-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .modal-admin-header h2 { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .modal-admin-header h2 i { color: var(--gold-dark); }
        .modal-close-btn { background: none; border: 1px solid rgba(37,99,235,0.1); width: 32px; height: 32px; border-radius: 4px; font-size: 1rem; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
        .modal-close-btn:hover { background: rgba(239,68,68,0.08); color: #ef4444; border-color: rgba(239,68,68,0.2); }
        .modal-body { padding: 1.75rem; }
        .modal-footer { padding: 1rem 1.75rem; background: rgba(37,99,235,0.02); border-top: 1px solid rgba(37,99,235,0.1); display: flex; gap: 0.6rem; justify-content: flex-end; }

        /* Modal detail sections */
        .detail-section { margin-bottom: 1.5rem; }
        .detail-section:last-child { margin-bottom: 0; }
        .detail-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gold-dark); margin-bottom: 0.75rem; padding-bottom: 0.4rem; border-bottom: 1px solid rgba(37,99,235,0.08); display: flex; align-items: center; gap: 0.5rem; }
        .detail-title i { font-size: 0.9rem; }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; }
        .detail-item .detail-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.15rem; }
        .detail-item .detail-value { font-size: 0.9rem; color: var(--text-primary); font-weight: 500; }
        .detail-item .detail-value.price-val { font-size: 1rem; color: var(--gold-dark); font-weight: 800; }

        .status-display { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.75rem; border-radius: 3px; font-size: 0.8rem; font-weight: 600; }
        .status-display.pending  { background: rgba(245,158,11,0.1); color: #d97706; }
        .status-display.approved { background: rgba(34,197,94,0.1);  color: #16a34a; }
        .status-display.rejected { background: rgba(239,68,68,0.1);  color: #dc2626; }

        .admin-notes-box { background: rgba(239,68,68,0.04); border: 1px solid rgba(239,68,68,0.1); border-left: 3px solid #ef4444; padding: 0.75rem 1rem; border-radius: 3px; margin-top: 0.5rem; }
        .admin-notes-box .notes-label { font-size: 0.65rem; font-weight: 700; color: #ef4444; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .admin-notes-box .notes-text { font-size: 0.85rem; color: var(--text-primary); line-height: 1.5; }

        .commission-box { background: rgba(34,197,94,0.04); border: 1px solid rgba(34,197,94,0.1); border-left: 3px solid #22c55e; padding: 0.75rem 1rem; border-radius: 3px; margin-top: 0.5rem; }
        .commission-box .comm-label { font-size: 0.65rem; font-weight: 700; color: #16a34a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .commission-box .comm-value { font-size: 1rem; color: #16a34a; font-weight: 800; }

        /* Property gallery in modal */
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

        /* Documents list in modal */
        .documents-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .document-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.65rem 0.75rem; background: rgba(37,99,235,0.03); border-radius: 3px; border: 1px solid rgba(37,99,235,0.06); }
        .document-icon { font-size: 1.25rem; color: var(--gold-dark); }
        .document-info { flex: 1; }
        .document-name { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
        .document-meta { font-size: 0.7rem; color: var(--text-secondary); }
        .document-actions { display: flex; gap: 0.35rem; }
        .btn-doc { padding: 0.3rem 0.5rem; font-size: 0.7rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 0.2rem; }
        .btn-preview-doc { background: var(--gold); color: var(--text-primary); }
        .btn-preview-doc:hover { background: var(--gold-dark); }
        .btn-download-doc { background: var(--blue); color: #fff; }
        .btn-download-doc:hover { background: var(--blue-dark); }

        /* Modal buttons */
        .btn-modal { padding: 0.55rem 1.25rem; font-size: 0.85rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.15s; }
        .btn-modal:hover { transform: translateY(-1px); }
        .btn-modal-primary { background: var(--gold); color: var(--text-primary); }
        .btn-modal-primary:hover { background: var(--gold-dark); }
        .btn-modal-success { background: #22c55e; color: #fff; }
        .btn-modal-success:hover { background: #16a34a; }
        .btn-modal-danger { background: #ef4444; color: #fff; }
        .btn-modal-danger:hover { background: #dc2626; }
        .btn-modal-secondary { background: rgba(37,99,235,0.08); color: var(--text-secondary); }
        .btn-modal-secondary:hover { background: rgba(37,99,235,0.15); color: var(--text-primary); }
        .btn-modal-blue { background: var(--blue); color: #fff; }
        .btn-modal-blue:hover { background: var(--blue-dark); }

        /* Processing overlay */
        .processing-overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.4); z-index: 2000; }
        .processing-overlay.show { display: flex; }
        .processing-box { display: flex; align-items: center; gap: 0.75rem; background: var(--card-bg); color: var(--text-primary); padding: 1rem 1.5rem; border-radius: 4px; border: 1px solid rgba(37,99,235,0.1); box-shadow: 0 8px 32px rgba(0,0,0,0.15); }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .sales-grid { grid-template-columns: 1fr; }
            .modal-container { width: 98%; }
        }

        /* ===== FINALIZE MODAL OVERRIDES ===== */
        #finalizeSaleModal .modal-header { background: var(--card-bg); border-bottom: 1px solid rgba(37,99,235,0.1); position: relative; }
        #finalizeSaleModal .modal-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        #finalizeSaleModal .modal-title { color: var(--text-primary); font-weight: 700; }
        #finalizeSaleModal .modal-title i { color: var(--gold-dark); }
    </style>
</head>
<body>
    <?php
    $active_page = 'admin_property_sale_approvals.php';
    include 'admin_sidebar.php';
    include 'admin_navbar.php';
    ?>

    <div class="admin-content">
        <!-- Success / Error messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

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
                        <?php foreach ($display as $sale): ?>
                            <div class="sale-card" data-verification='<?= htmlspecialchars(json_encode($sale), ENT_QUOTES) ?>'>
                                <!-- Image -->
                                <div class="card-img-wrap">
                                    <?php if ($sale['property_image']): ?>
                                        <img src="<?= htmlspecialchars($sale['property_image']) ?>" alt="Property" onerror="this.src='uploads/default-property.jpg'">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#adb5bd;"><i class="bi bi-image" style="font-size:2.5rem;"></i></div>
                                    <?php endif; ?>
                                    <div class="img-overlay"></div>
                                    <div class="type-badge"><i class="bi bi-house-door me-1"></i><?= htmlspecialchars($sale['PropertyType']) ?></div>
                                    <?php
                                        $badgeClass = strtolower($sale['status']);
                                        $badgeLabel = $sale['status'] === 'Approved' ? 'SOLD' : strtoupper($sale['status']);
                                    ?>
                                    <div class="status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></div>
                                    <div class="price-overlay"><div class="price">₱<?= number_format($sale['sale_price'], 0) ?></div></div>
                                </div>

                                <!-- Body -->
                                <div class="card-body-content">
                                    <h3 class="prop-address"><?= htmlspecialchars($sale['StreetAddress']) ?></h3>
                                    <div class="prop-location"><i class="bi bi-geo-alt-fill"></i><?= htmlspecialchars($sale['City']) ?></div>

                                    <div class="sale-info">
                                        <div class="sale-info-row">
                                            <span class="sale-info-label">Sale Price</span>
                                            <span class="sale-info-value sale-price">₱<?= number_format($sale['sale_price'], 2) ?></span>
                                        </div>
                                        <div class="sale-info-row">
                                            <span class="sale-info-label">Listing Price</span>
                                            <span class="sale-info-value">₱<?= number_format($sale['ListingPrice'], 2) ?></span>
                                        </div>
                                        <div class="sale-info-row">
                                            <span class="sale-info-label">Sale Date</span>
                                            <span class="sale-info-value"><?= htmlspecialchars($sale['sale_date_fmt']) ?></span>
                                        </div>
                                    </div>

                                    <div class="people-section buyer">
                                        <div class="section-title"><i class="bi bi-person-fill me-1"></i>Buyer</div>
                                        <div class="detail-line"><i class="bi bi-person"></i><?= htmlspecialchars($sale['buyer_name']) ?></div>
                                        <?php if ($sale['buyer_contact']): ?>
                                            <div class="detail-line"><i class="bi bi-telephone"></i><?= htmlspecialchars($sale['buyer_contact']) ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="people-section agent">
                                        <div class="section-title"><i class="bi bi-person-badge me-1"></i>Agent</div>
                                        <div class="detail-line"><i class="bi bi-person-check"></i><?= htmlspecialchars($sale['agent_first_name'] . ' ' . $sale['agent_last_name']) ?></div>
                                        <?php if ($sale['document_count'] > 0): ?>
                                            <div class="doc-badge"><i class="bi bi-file-earmark-text"></i><?= $sale['document_count'] ?> Document<?= $sale['document_count'] > 1 ? 's' : '' ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="card-actions">
                                        <button class="btn-action btn-view" onclick="viewDetails(<?= $sale['verification_id'] ?>)">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <?php if ($sale['status'] === 'Pending'): ?>
                                            <button class="btn-action btn-approve" onclick="approveVerification(<?= $sale['verification_id'] ?>)">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                            <button class="btn-action btn-reject" onclick="rejectVerification(<?= $sale['verification_id'] ?>)">
                                                <i class="bi bi-x-lg"></i> Reject
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal-overlay" id="detailsModal">
        <div class="modal-container modal-large">
            <div class="modal-admin-header">
                <h2><i class="bi bi-file-earmark-check"></i> Sale Verification Details</h2>
                <button class="modal-close-btn" onclick="closeModal('detailsModal')">&times;</button>
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
        <div class="modal-container" style="max-width:500px;">
            <div class="modal-admin-header">
                <h2 id="confirmTitle"><i class="bi bi-question-circle"></i> Confirm Action</h2>
                <button class="modal-close-btn" onclick="closeModal('confirmModal')">&times;</button>
            </div>
            <div class="modal-body" id="confirmBody"></div>
            <div class="modal-footer">
                <button class="btn-modal btn-modal-secondary" onclick="closeModal('confirmModal')">Cancel</button>
                <button class="btn-modal btn-modal-success" id="confirmActionBtn">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Rejection Reason Modal -->
    <div class="modal-overlay" id="reasonModal">
        <div class="modal-container" style="max-width:500px;">
            <div class="modal-admin-header">
                <h2><i class="bi bi-chat-left-text"></i> Reject – Provide Reason</h2>
                <button class="modal-close-btn" onclick="closeModal('reasonModal')">&times;</button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-bold" for="reasonInput" style="font-size:0.85rem;">Reason for rejection:</label>
                <textarea class="form-control" id="reasonInput" rows="4" placeholder="Explain why this verification is being rejected..." style="border:1px solid rgba(37,99,235,0.15);border-radius:3px;font-size:0.9rem;"></textarea>
                <div id="reasonError" style="color:#ef4444;font-size:0.8rem;margin-top:0.35rem;display:none;">A reason is required.</div>
            </div>
            <div class="modal-footer">
                <button class="btn-modal btn-modal-secondary" onclick="closeModal('reasonModal')">Cancel</button>
                <button class="btn-modal btn-modal-danger" id="submitRejectBtn"><i class="bi bi-x-lg me-1"></i>Reject</button>
            </div>
        </div>
    </div>

    <!-- Finalize Sale & Commission Modal -->
    <div class="modal fade" id="finalizeSaleModal" tabindex="-1" aria-labelledby="finalizeSaleLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border:1px solid rgba(37,99,235,0.1);border-radius:4px;">
                <div class="modal-header" style="position:relative;">
                    <h5 class="modal-title" id="finalizeSaleLabel"><i class="bi bi-cash-coin me-2"></i>Finalize Sale & Commission</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="finalizeSaleForm">
                    <div class="modal-body">
                        <input type="hidden" name="property_id" id="finalize_property_id">
                        <input type="hidden" name="agent_id" id="finalize_agent_id">
                        <div class="mb-3">
                            <label for="final_sale_price" class="form-label fw-semibold" style="font-size:0.85rem;">Final Sale Price (₱)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="final_sale_price" name="final_sale_price" required>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="buyer_name" class="form-label fw-semibold" style="font-size:0.85rem;">Buyer Name</label>
                                <input type="text" class="form-control" id="buyer_name" name="buyer_name">
                            </div>
                            <div class="col-md-6">
                                <label for="buyer_email" class="form-label fw-semibold" style="font-size:0.85rem;">Buyer Email</label>
                                <input type="email" class="form-control" id="buyer_email" name="buyer_email">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label for="buyer_contact" class="form-label fw-semibold" style="font-size:0.85rem;">Buyer Contact</label>
                            <input type="text" class="form-control" id="buyer_contact" name="buyer_contact">
                        </div>
                        <div class="mt-3">
                            <label for="commission_percentage" class="form-label fw-semibold" style="font-size:0.85rem;">Commission Percentage (%)</label>
                            <input type="number" step="0.01" min="0" max="100" class="form-control" id="commission_percentage" name="commission_percentage" required>
                        </div>
                        <div class="mt-3">
                            <label for="notes" class="form-label fw-semibold" style="font-size:0.85rem;">Notes (optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                        </div>
                        <div class="mt-2 small text-muted" id="finalizeHelp"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm" style="background:var(--gold);color:var(--text-primary);font-weight:600;"><i class="bi bi-check2-circle me-1"></i>Save & Calculate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Processing Overlay -->
    <div id="processingOverlay" class="processing-overlay">
        <div class="processing-box">
            <div class="spinner-border spinner-border-sm" role="status"></div>
            <div style="font-weight:600;">Processing, please wait...</div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ===== DATA =====
    const saleVerifications = <?= json_encode($sale_verifications) ?>;
    let currentViewedSale = null;
    let currentDocId = null, currentDocName = '';

    // ===== MODAL HELPERS =====
    function openModal(id) { document.getElementById(id).classList.add('show'); document.body.style.overflow = 'hidden'; }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow = ''; }
    document.querySelectorAll('.modal-overlay').forEach(o => {
        o.addEventListener('click', e => { if (e.target === o) closeModal(o.id); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => closeModal(m.id));
    });

    function showProcessing(msg) {
        const o = document.getElementById('processingOverlay');
        if (msg) o.querySelector('div:last-child').textContent = msg;
        o.classList.add('show'); document.body.style.overflow = 'hidden';
    }
    function hideProcessing() { document.getElementById('processingOverlay').classList.remove('show'); document.body.style.overflow = ''; }

    // ===== VIEW DETAILS =====
    function viewDetails(vid) {
        const sale = saleVerifications.find(s => s.verification_id == vid);
        if (!sale) return;
        currentViewedSale = sale;

        const stClass = sale.status.toLowerCase();
        const stLabel = sale.status === 'Approved' ? 'Sold' : sale.status;

        let html = '';

        // Gallery
        if (sale.property_images && sale.property_images.length > 0) {
            html += `<div class="detail-section">
                <div class="detail-title"><i class="bi bi-images"></i> Property Images (${sale.property_image_count || 0})</div>
                <div class="property-gallery">
                    ${sale.property_images.map((img, i) => `<div class="gallery-item ${i === 0 ? 'active' : ''}" data-index="${i}"><img src="${img.url}" alt="Image ${i+1}" class="gallery-image"></div>`).join('')}
                </div>
                ${sale.property_images.length > 1 ? `
                <div class="gallery-navigation">
                    <button class="gallery-nav-btn" onclick="prevImg()" id="prevBtn" disabled><i class="bi bi-chevron-left"></i></button>
                    <div class="gallery-indicators">${sale.property_images.map((_, i) => `<button class="gallery-indicator ${i === 0 ? 'active' : ''}" onclick="goToImg(${i})"></button>`).join('')}</div>
                    <button class="gallery-nav-btn" onclick="nextImg()" id="nextBtn"><i class="bi bi-chevron-right"></i></button>
                </div>` : ''}
            </div>`;
        }

        // Property info
        html += `<div class="detail-section">
            <div class="detail-title"><i class="bi bi-building"></i> Property Information</div>
            <div class="detail-grid">
                <div class="detail-item"><div class="detail-label">Address</div><div class="detail-value">${esc(sale.StreetAddress)}</div></div>
                <div class="detail-item"><div class="detail-label">City</div><div class="detail-value">${esc(sale.City)}</div></div>
                <div class="detail-item"><div class="detail-label">Type</div><div class="detail-value">${esc(sale.PropertyType)}</div></div>
                <div class="detail-item"><div class="detail-label">Listing Price</div><div class="detail-value">₱${Number(sale.ListingPrice).toLocaleString()}</div></div>
            </div>
        </div>`;

        // Sale info
        html += `<div class="detail-section">
            <div class="detail-title"><i class="bi bi-handshake"></i> Sale Information</div>
            <div class="detail-grid">
                <div class="detail-item"><div class="detail-label">Sale Price</div><div class="detail-value price-val">₱${Number(sale.sale_price).toLocaleString()}</div></div>
                <div class="detail-item"><div class="detail-label">Sale Date</div><div class="detail-value">${sale.sale_date_fmt}</div></div>
                <div class="detail-item"><div class="detail-label">Status</div><div class="detail-value"><span class="status-display ${stClass}"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> ${stLabel}</span></div></div>
                <div class="detail-item"><div class="detail-label">Submitted</div><div class="detail-value">${sale.submitted_at_fmt}</div></div>
                ${sale.reviewed_at ? `<div class="detail-item"><div class="detail-label">Reviewed</div><div class="detail-value">${sale.reviewed_at_fmt}</div></div>` : ''}
            </div>
            ${sale.admin_notes ? `<div class="admin-notes-box"><div class="notes-label">Rejection Reason</div><div class="notes-text">${esc(sale.admin_notes)}</div></div>` : ''}
            ${sale.commission_amount ? `<div class="commission-box"><div class="comm-label">Commission (${Number(sale.commission_percentage)}%)</div><div class="comm-value">₱${Number(sale.commission_amount).toLocaleString(undefined,{minimumFractionDigits:2})}</div></div>` : ''}
        </div>`;

        // Buyer info
        html += `<div class="detail-section">
            <div class="detail-title"><i class="bi bi-person-fill"></i> Buyer Information</div>
            <div class="detail-grid">
                <div class="detail-item"><div class="detail-label">Name</div><div class="detail-value">${esc(sale.buyer_name)}</div></div>
                ${sale.buyer_contact ? `<div class="detail-item"><div class="detail-label">Contact</div><div class="detail-value">${esc(sale.buyer_contact)}</div></div>` : ''}
                ${sale.additional_notes ? `<div class="detail-item" style="grid-column:1/-1;"><div class="detail-label">Notes</div><div class="detail-value">${esc(sale.additional_notes)}</div></div>` : ''}
            </div>
        </div>`;

        // Agent info
        html += `<div class="detail-section">
            <div class="detail-title"><i class="bi bi-person-badge"></i> Agent Information</div>
            <div class="detail-grid">
                <div class="detail-item"><div class="detail-label">Name</div><div class="detail-value">${esc(sale.agent_first_name)} ${esc(sale.agent_last_name)}</div></div>
                <div class="detail-item"><div class="detail-label">Email</div><div class="detail-value">${esc(sale.agent_email)}</div></div>
            </div>
        </div>`;

        // Documents
        if (sale.documents && sale.documents.length > 0) {
            html += `<div class="detail-section">
                <div class="detail-title"><i class="bi bi-file-earmark-text"></i> Supporting Documents (${sale.document_count})</div>
                <div class="documents-list">
                    ${sale.documents.map(doc => {
                        const ext = (doc.original_filename || '').split('.').pop().toLowerCase();
                        const isImg = ['jpg','jpeg','png','gif','webp'].includes(ext);
                        const isPdf = ext === 'pdf';
                        const icon = isImg ? 'bi-file-image' : isPdf ? 'bi-file-pdf' : 'bi-file-earmark';
                        return `<div class="document-item">
                            <div class="document-icon"><i class="bi ${icon}"></i></div>
                            <div class="document-info">
                                <div class="document-name">${esc(doc.original_filename)}</div>
                                <div class="document-meta">${formatSize(doc.file_size)} • ${new Date(doc.uploaded_at).toLocaleDateString()}</div>
                            </div>
                            <div class="document-actions">
                                ${isImg || isPdf ? `<button class="btn-doc btn-preview-doc" onclick="previewDoc('${doc.file_path}','${doc.mime_type}','${esc(doc.original_filename)}',${doc.id})"><i class="bi bi-eye"></i></button>` : ''}
                                <button class="btn-doc btn-download-doc" onclick="downloadDoc(${doc.id})"><i class="bi bi-download"></i></button>
                            </div>
                        </div>`;
                    }).join('')}
                </div>
            </div>`;
        }

        document.getElementById('modalContent').innerHTML = html;

        // Footer buttons
        let footer = '';
        if (sale.status === 'Pending') {
            footer = `<button class="btn-modal btn-modal-success" onclick="approveFromModal(${vid})"><i class="bi bi-check-lg me-1"></i>Approve</button>
                      <button class="btn-modal btn-modal-danger" onclick="rejectFromModal(${vid})"><i class="bi bi-x-lg me-1"></i>Reject</button>`;
        }
        if (sale.status === 'Approved') {
            const hasCommission = sale.commission_amount && Number(sale.commission_amount) > 0;
            footer = `<button class="btn-modal btn-modal-primary" onclick="openFinalizeModal()"><i class="bi bi-cash-coin me-1"></i>${hasCommission ? 'Edit' : 'Finalize'} Commission</button>`;
        }
        footer += `<button class="btn-modal btn-modal-secondary" onclick="closeModal('detailsModal')">Close</button>`;
        document.getElementById('modalFooter').innerHTML = footer;

        openModal('detailsModal');
        setTimeout(initGallery, 100);
    }

    // ===== GALLERY =====
    let galleryIdx = 0, galleryTotal = 0;
    function initGallery() {
        galleryTotal = document.querySelectorAll('.gallery-item').length;
        galleryIdx = 0;
        updateGallery();
    }
    function updateGallery() {
        document.querySelectorAll('.gallery-item').forEach((el, i) => el.classList.toggle('active', i === galleryIdx));
        document.querySelectorAll('.gallery-indicator').forEach((el, i) => el.classList.toggle('active', i === galleryIdx));
        const p = document.getElementById('prevBtn'), n = document.getElementById('nextBtn');
        if (p) p.disabled = galleryIdx === 0;
        if (n) n.disabled = galleryIdx >= galleryTotal - 1;
    }
    function nextImg() { if (galleryIdx < galleryTotal - 1) { galleryIdx++; updateGallery(); } }
    function prevImg() { if (galleryIdx > 0) { galleryIdx--; updateGallery(); } }
    function goToImg(i) { galleryIdx = i; updateGallery(); }

    // ===== APPROVE / REJECT =====
    function approveVerification(vid) { approveFromModal(vid); }
    function rejectVerification(vid) { rejectFromModal(vid); }

    function approveFromModal(vid) {
        document.getElementById('confirmTitle').innerHTML = '<i class="bi bi-check-circle"></i> Approve Sale';
        document.getElementById('confirmBody').innerHTML = '<p style="margin:0;font-size:0.9rem;color:var(--text-secondary);">Are you sure you want to approve this sale? The property will be marked as <strong>SOLD</strong> and both the agent and buyer will be notified by email.</p>';
        const btn = document.getElementById('confirmActionBtn');
        const newBtn = btn.cloneNode(true);
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
        showProcessing(action === 'approve' ? 'Approving sale...' : 'Rejecting sale...');
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
        document.getElementById('buyer_email').value = sale.buyer_email || '';
        document.getElementById('buyer_contact').value = sale.buyer_contact || '';
        document.getElementById('commission_percentage').value = sale.commission_percentage || '';
        document.getElementById('notes').value = '';
        document.getElementById('finalizeHelp').textContent = `Property #${sale.property_id} • Agent: ${sale.agent_first_name} ${sale.agent_last_name}`;
        if (finalizeModalInstance) finalizeModalInstance.show();
    }

    const ff = document.getElementById('finalizeSaleForm');
    if (ff) ff.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(ff);
        const price = parseFloat(fd.get('final_sale_price'));
        const pct = parseFloat(fd.get('commission_percentage'));
        if (!price || price <= 0) { alert('Enter a valid sale price.'); return; }
        if (isNaN(pct) || pct < 0 || pct > 100) { alert('Commission must be 0-100%.'); return; }
        showProcessing('Finalizing sale...');
        try {
            const res = await fetch('admin_finalize_sale.php', { method: 'POST', body: fd });
            const data = await res.json();
            hideProcessing();
            if (data.ok) {
                if (finalizeModalInstance) finalizeModalInstance.hide();
                alert('Commission saved: ₱' + Number(data.commission_amount).toLocaleString(undefined, {minimumFractionDigits:2}));
                location.href = location.pathname + '?success=finalized';
            } else {
                alert(data.message || 'Failed to finalize.');
            }
        } catch (err) { hideProcessing(); alert('Error finalizing sale.'); console.error(err); }
    });

    // ===== UTILITY =====
    function esc(s) { if (!s) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function formatSize(b) {
        if (!b) return '0 B';
        const k = 1024, s = ['B','KB','MB','GB'];
        const i = Math.floor(Math.log(b) / Math.log(k));
        return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + s[i];
    }
    </script>
</body>
</html>
