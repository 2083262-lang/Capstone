<?php
/**
 * process_commission_payment.php
 *
 * Handles the admin action of marking a commission as paid,
 * including payment proof upload and audit logging.
 *
 * Method: POST (multipart/form-data)
 * Required role: admin
 */

session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/agent_pages/agent_notification_helper.php';

header('Content-Type: application/json');

try {
    // ── 1. Auth check ──
    if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Invalid request method']);
        exit;
    }

    $admin_id       = (int) $_SESSION['account_id'];
    $commission_id  = isset($_POST['commission_id']) ? (int) $_POST['commission_id'] : 0;
    $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
    $payment_ref    = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : '';
    $payment_notes  = isset($_POST['payment_notes']) ? trim($_POST['payment_notes']) : '';

    // ── 2. Input validation ──
    $errors = [];
    if ($commission_id <= 0) $errors[] = 'Invalid commission ID.';

    $allowed_methods = ['bank_transfer', 'gcash', 'maya', 'cash', 'check', 'other'];
    if (!in_array($payment_method, $allowed_methods, true)) {
        $errors[] = 'Invalid payment method.';
    }

    if (empty($payment_ref)) {
        $errors[] = 'Payment reference/transaction number is required.';
    } elseif (strlen($payment_ref) > 100) {
        $errors[] = 'Payment reference must be 100 characters or fewer.';
    }

    // ── 3. File upload validation ──
    $proof_path     = null;
    $proof_original = null;
    $proof_mime     = null;
    $proof_size     = null;
    $ext            = null;

    $allowed_mimes = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf'
    ];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    $max_file_size = 5 * 1024 * 1024; // 5 MB

    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['payment_proof'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
            ];
            $errors[] = $upload_errors[$file['error']] ?? 'File upload error (code: ' . $file['error'] . ').';
        } else {
            // Validate MIME via finfo (not trusting client-reported type)
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected_mime = $finfo->file($file['tmp_name']);

            if (!in_array($detected_mime, $allowed_mimes, true)) {
                $errors[] = 'Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF.';
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_extensions, true)) {
                $errors[] = 'Invalid file extension.';
            }

            if ($file['size'] > $max_file_size) {
                $errors[] = 'File too large. Maximum: 5 MB.';
            }

            if ($file['size'] === 0) {
                $errors[] = 'Uploaded file is empty.';
            }

            $proof_original = basename($file['name']);
            $proof_mime     = $detected_mime;
            $proof_size     = $file['size'];
        }
    } else {
        $errors[] = 'Payment proof file is required.';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // ── 4. Database transaction ──
    $conn->begin_transaction();

    // Lock the commission row to prevent race conditions
    $stmt = $conn->prepare("
        SELECT ac.*, fs.property_id, fs.final_sale_price, fs.agent_id AS fs_agent_id,
               a.first_name, a.last_name, a.email AS agent_email,
               p.StreetAddress, p.City, p.PropertyType
        FROM agent_commissions ac
        JOIN finalized_sales fs ON fs.sale_id = ac.sale_id
        JOIN accounts a ON a.account_id = ac.agent_id
        LEFT JOIN property p ON p.property_ID = fs.property_id
        WHERE ac.commission_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $commission_id);
    $stmt->execute();
    $commission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$commission) {
        throw new Exception('Commission record not found.');
    }

    // ── 5. Double-payment protection ──
    if ($commission['status'] === 'paid') {
        throw new Exception('This commission has already been paid on '
            . date('M j, Y g:i A', strtotime($commission['paid_at'])) . '.');
    }

    if (!in_array($commission['status'], ['calculated', 'processing'], true)) {
        throw new Exception('Only calculated or processing commissions can be marked as paid. Current status: ' . $commission['status']);
    }

    // ── 6. Save proof file ──
    $upload_dir = __DIR__ . '/uploads/commission_proofs/' . $commission_id . '/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory.');
        }
    }

    // Generate secure filename (never use user-provided name)
    $safe_name     = 'proof_' . $commission_id . '_' . uniqid('', true) . '.' . $ext;
    $dest_path     = $upload_dir . $safe_name;
    $relative_path = 'uploads/commission_proofs/' . $commission_id . '/' . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        throw new Exception('Failed to save payment proof file.');
    }

    // ── 7. Update commission record ──
    $now = date('Y-m-d H:i:s');
    $upd = $conn->prepare("
        UPDATE agent_commissions
        SET status = 'paid',
            paid_at = ?,
            paid_by = ?,
            payment_method = ?,
            payment_reference = ?,
            payment_proof_path = ?,
            payment_proof_original_name = ?,
            payment_proof_mime = ?,
            payment_proof_size = ?,
            payment_notes = ?
        WHERE commission_id = ?
        AND status IN ('calculated', 'processing')
    ");
    $upd->bind_param(
        'sisssssisi',
        $now,           // paid_at
        $admin_id,      // paid_by
        $payment_method,
        $payment_ref,
        $relative_path,
        $proof_original,
        $proof_mime,
        $proof_size,
        $payment_notes,
        $commission_id
    );

    if (!$upd->execute() || $upd->affected_rows === 0) {
        // Clean up uploaded file since DB update failed
        if (file_exists($dest_path)) {
            @unlink($dest_path);
        }
        throw new Exception('Failed to update commission. It may have been modified by another process.');
    }
    $upd->close();

    // ── 8. Audit log ──
    $log_details = json_encode([
        'payment_method'    => $payment_method,
        'payment_reference' => $payment_ref,
        'proof_file'        => $relative_path,
        'commission_amount' => $commission['commission_amount'],
        'agent_id'          => $commission['agent_id'],
        'admin_id'          => $admin_id,
        'admin_ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ], JSON_UNESCAPED_UNICODE);

    $old_status = $commission['status'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $log = $conn->prepare("
        INSERT INTO commission_payment_logs
            (commission_id, action, old_status, new_status, details, performed_by, ip_address)
        VALUES (?, 'paid', ?, 'paid', ?, ?, ?)
    ");
    $log->bind_param('issis', $commission_id, $old_status, $log_details, $admin_id, $ip);
    $log->execute();
    $log->close();

    // ── 9. Agent notification ──
    $fmtAmount = '₱' . number_format((float)$commission['commission_amount'], 2);
    createAgentNotification(
        $conn,
        (int) $commission['agent_id'],
        'commission_paid',
        'Commission Paid',
        "Your commission of {$fmtAmount} for Property #{$commission['property_id']} has been paid. "
        . "Reference: {$payment_ref}. Check your commissions page for details.",
        $commission_id
    );

    // ── 10. Admin notification ──
    $agentName = trim($commission['first_name'] . ' ' . $commission['last_name']);
    $adminMsg  = "Commission #{$commission_id} ({$fmtAmount}) paid to {$agentName} for Property #{$commission['property_id']}. Ref: {$payment_ref}";
    $n = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, created_at)
                          VALUES (?, 'property_sale', 'Commission Paid', ?, 'update', 'normal', NOW())");
    $n->bind_param('is', $commission_id, $adminMsg);
    $n->execute();
    $n->close();

    $conn->commit();

    // ── 11. Email to agent (best-effort, after commit) ──
    $emailSent = false;
    try {
        if (!empty($commission['agent_email'])) {
            $propAddr  = trim(($commission['StreetAddress'] ?? '') . ', ' . ($commission['City'] ?? ''));
            $propType  = $commission['PropertyType'] ?? 'Property';
            $fmtPct    = number_format((float)$commission['commission_percentage'], 2) . '%';
            $fmtDate   = date('F j, Y');

            // Payment method display label
            $methodLabels = [
                'bank_transfer' => 'Bank Transfer',
                'gcash'         => 'GCash',
                'maya'          => 'Maya',
                'cash'          => 'Cash',
                'check'         => 'Check',
                'other'         => 'Other',
            ];
            $methodLabel = $methodLabels[$payment_method] ?? ucfirst($payment_method);

            $bodyContent  = emailGreeting($agentName, 'Great News');
            $bodyContent .= emailParagraph(
                'Your commission has been <strong style="color:#22c55e;">paid</strong>! '
                . 'Here are the complete details of your commission payment.'
            );

            $bodyContent .= emailInfoCard('Sale Details', [
                'Property'        => htmlspecialchars($propAddr),
                'Type'            => htmlspecialchars($propType),
                'Commission Rate' => '<span style="color:#ffffff;font-weight:700;">' . $fmtPct . '</span>',
            ]);

            $bodyContent .= emailInfoCard('Payment Details', [
                'Commission Amount' => '<span style="color:#22c55e;font-weight:700;font-size:16px;">' . $fmtAmount . '</span>',
                'Payment Method'    => '<span style="color:#d4af37;font-weight:700;">' . htmlspecialchars($methodLabel) . '</span>',
                'Reference Number'  => '<span style="font-family:monospace;color:#ffffff;">' . htmlspecialchars($payment_ref) . '</span>',
                'Date Paid'         => $fmtDate,
                'Status'            => '<span style="color:#22c55e;font-weight:700;">PAID</span>',
            ], '#22c55e');

            if (!empty($payment_notes)) {
                $bodyContent .= emailInfoCard('Payment Notes', [
                    'Notes' => htmlspecialchars($payment_notes),
                ]);
            }

            $bodyContent .= emailDivider();
            $bodyContent .= emailNotice(
                'Payment Received',
                'This commission has been officially marked as paid. If you have any questions or concerns about this payment, '
                . 'please contact the admin team through your <strong style="color:#d4af37;">Agent Dashboard</strong>.',
                '#22c55e'
            );
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#22c55e',
                'heading'     => 'Commission Paid',
                'subtitle'    => 'Your commission payment has been processed',
                'body'        => $bodyContent,
                'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
            ]);

            $alt = "Great News {$agentName},\n\n"
                 . "Your commission has been paid!\n\n"
                 . "Commission Amount: {$fmtAmount}\n"
                 . "Payment Method: {$methodLabel}\n"
                 . "Reference: {$payment_ref}\n"
                 . "Date: {$fmtDate}\n\n"
                 . "You can view the full details in your Agent Dashboard.\n\n"
                 . "Best regards,\nThe HomeEstate Realty Team";

            $mailResult = sendSystemMail(
                $commission['agent_email'],
                $agentName,
                'Commission Paid – ' . $fmtAmount . ' for ' . $propAddr,
                $html,
                $alt
            );
            $emailSent = !empty($mailResult['success']);
        }
    } catch (Exception $mailEx) {
        // Email failure should not break the success response
        error_log('[COMMISSION PAYMENT EMAIL] ' . $mailEx->getMessage());
    }

    echo json_encode([
        'ok'         => true,
        'message'    => 'Commission marked as paid successfully.',
        'paid_at'    => $now,
        'email_sent' => $emailSent
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        $conn->rollback();
    }
    // Clean up uploaded file if it was saved before error
    if (isset($dest_path) && file_exists($dest_path)) {
        @unlink($dest_path);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
