<?php
session_start();
require_once '../connection.php';
require_once __DIR__ . '/../config/session_timeout.php';
require_once __DIR__ . '/../mail_helper.php';
require_once __DIR__ . '/../email_template.php';

function respond_json($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra));
    exit();
}

if (!isset($_SESSION['account_id']) || !in_array($_SESSION['user_role'], ['agent', 'admin'])) {
    respond_json(false, 'Unauthorized access.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(false, 'Invalid request method.');
}

try {
    $agent_id = (int) $_SESSION['account_id'];

    // Required fields
    $rental_id      = isset($_POST['rental_id']) ? (int)$_POST['rental_id'] : 0;
    $payment_amount = isset($_POST['payment_amount']) ? floatval($_POST['payment_amount']) : 0;
    $payment_date   = trim($_POST['payment_date'] ?? '');
    $period_start   = trim($_POST['period_start'] ?? '');
    $period_end     = trim($_POST['period_end'] ?? '');
    $notes          = isset($_POST['additional_notes']) ? trim(strip_tags(substr($_POST['additional_notes'], 0, 2000))) : null;

    if ($rental_id <= 0) respond_json(false, 'Invalid rental ID.');
    if ($payment_amount <= 0 || $payment_amount > 99999999.99) respond_json(false, 'Payment amount must be between 0.01 and 99,999,999.99.');

    // Validate dates
    $pd = DateTime::createFromFormat('Y-m-d', $payment_date);
    if (!$pd) respond_json(false, 'Invalid payment date.');
    if ($pd > new DateTime()) respond_json(false, 'Payment date cannot be in the future.');

    $ps = DateTime::createFromFormat('Y-m-d', $period_start);
    $pe = DateTime::createFromFormat('Y-m-d', $period_end);
    if (!$ps || !$pe) respond_json(false, 'Invalid period dates.');
    if ($ps >= $pe) respond_json(false, 'Period start must be before period end.');

    // Validate file(s)
    if (!isset($_FILES['payment_documents']) || empty($_FILES['payment_documents']['name'][0])) {
        respond_json(false, 'At least one proof of payment document is required.');
    }

    // Verify lease ownership and active status
    $lease_stmt = $conn->prepare("
        SELECT fr.*, p.StreetAddress, p.City,
               fr.tenant_name, fr.tenant_email
        FROM finalized_rentals fr
        JOIN property p ON fr.property_id = p.property_ID
        WHERE fr.rental_id = ? AND fr.agent_id = ?
    ");
    $lease_stmt->bind_param("ii", $rental_id, $agent_id);
    $lease_stmt->execute();
    $lease = $lease_stmt->get_result()->fetch_assoc();
    $lease_stmt->close();

    if (!$lease) respond_json(false, 'Lease not found or not authorized.');
    if (!in_array($lease['lease_status'], ['Active', 'Renewed'])) {
        respond_json(false, 'Cannot record payments for a ' . strtolower($lease['lease_status']) . ' lease.');
    }

    // Period overlap check (excluding rejected payments)
    $overlap = $conn->prepare("
        SELECT payment_id FROM rental_payments 
        WHERE rental_id = ? AND status IN ('Pending','Confirmed')
        AND period_start < ? AND period_end > ?
    ");
    $overlap->bind_param("iss", $rental_id, $period_end, $period_start);
    $overlap->execute();
    if ($overlap->get_result()->num_rows > 0) {
        respond_json(false, 'A payment record already exists for an overlapping period.');
    }
    $overlap->close();

    // Process file uploads
    $project_root = realpath(__DIR__ . '/..');
    $upload_base = $project_root . '/rental_payment_documents';
    if (!file_exists($upload_base)) mkdir($upload_base, 0755, true);
    $upload_dir = $upload_base . '/' . $rental_id;
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

    $uploaded_files = [];
    $allowed_types = ['application/pdf','image/jpeg','image/jpg','image/png','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $allowed_ext = ['pdf','jpg','jpeg','png','doc','docx'];
    $max_size = 120 * 1024 * 1024;

    $files = $_FILES['payment_documents'];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            respond_json(false, 'Upload error for: ' . basename($files['name'][$i]));
        }
        $orig = basename($files['name'][$i]);
        $tmp  = $files['tmp_name'][$i];
        $size = $files['size'][$i];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext)) respond_json(false, "Invalid file type: $orig");

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        if (!in_array($mime, $allowed_types)) respond_json(false, "Invalid content type for: $orig");
        if ($size > $max_size) respond_json(false, "File too large: $orig (max 120MB)");

        $stored = uniqid('rp_', true) . '_' . time() . '.' . $ext;
        $dest = $upload_dir . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) respond_json(false, "Failed to upload: $orig");

        // Store relative path from project root for consistent downloads
        $relative_path = 'rental_payment_documents/' . $rental_id . '/' . $stored;
        $uploaded_files[] = ['original_name' => $orig, 'stored_name' => $stored, 'file_path' => $relative_path, 'file_size' => $size, 'mime_type' => $mime];
    }

    // Transaction
    $conn->begin_transaction();
    try {
        // Insert payment
        $ins = $conn->prepare("
            INSERT INTO rental_payments (rental_id, agent_id, payment_amount, payment_date, period_start, period_end, additional_notes, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $ins->bind_param("iidssss", $rental_id, $agent_id, $payment_amount, $payment_date, $period_start, $period_end, $notes);
        if (!$ins->execute()) throw new Exception('Failed to insert payment record.');
        $payment_id = $conn->insert_id;
        $ins->close();

        // Insert documents
        $doc = $conn->prepare("
            INSERT INTO rental_payment_documents (payment_id, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        foreach ($uploaded_files as $f) {
            $doc->bind_param("isssis", $payment_id, $f['original_name'], $f['stored_name'], $f['file_path'], $f['file_size'], $f['mime_type']);
            if (!$doc->execute()) throw new Exception('Failed to insert document record.');
        }
        $doc->close();

        // Admin notification
        $notif_msg = "Rent payment recorded for {$lease['StreetAddress']}, {$lease['City']} — ₱" . number_format($payment_amount, 2) . " (Period: $period_start to $period_end)";
        $notif = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, is_read, created_at) VALUES (?, 'rental_payment', 'Rental Payment Submitted', ?, 'request', 'normal', 0, NOW())");
        $property_id = (int)$lease['property_id'];
        $notif->bind_param("is", $property_id, $notif_msg);
        $notif->execute();
        $notif->close();

        $conn->commit();

        // ── Email notification to tenant ──
        if (!empty($lease['tenant_email'])) {
            $tenantName  = $lease['tenant_name'] ?: 'Tenant';
            $propAddr    = $lease['StreetAddress'] . ', ' . $lease['City'];
            $fmtAmount   = '₱' . number_format($payment_amount, 2);
            $fmtPeriod   = date('M d, Y', strtotime($period_start)) . ' – ' . date('M d, Y', strtotime($period_end));
            $fmtPayDate  = date('M d, Y', strtotime($payment_date));

            $bodyContent  = emailGreeting($tenantName);
            $bodyContent .= emailParagraph('A rent payment has been recorded for your leased property and is now <strong style="color:#d4af37;">pending admin confirmation</strong>.');
            $bodyContent .= emailInfoCard('Payment Details', [
                'Property'       => htmlspecialchars($propAddr),
                'Amount'         => '<span style="color:#d4af37;font-weight:700;">' . $fmtAmount . '</span>',
                'Payment Date'   => $fmtPayDate,
                'Period Covered' => $fmtPeriod,
                'Status'         => '<span style="color:#f59e0b;font-weight:600;">Pending Confirmation</span>',
            ]);
            $bodyContent .= emailNotice('What Happens Next', 'The admin team will review and confirm this payment. You will receive another email once it has been approved.', '#2563eb');
            $bodyContent .= emailDivider();
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#d4af37',
                'heading'     => 'Rent Payment Recorded',
                'subtitle'    => 'Payment submitted for ' . htmlspecialchars($propAddr),
                'body'        => $bodyContent,
            ]);
            $alt = "Hello {$tenantName},\n\nA rent payment of {$fmtAmount} has been recorded for {$propAddr}.\nPayment Date: {$fmtPayDate}\nPeriod: {$fmtPeriod}\nStatus: Pending Confirmation\n\nThe admin team will review and confirm this payment shortly.\n\n– HomeEstate Realty";

            sendSystemMail($lease['tenant_email'], $tenantName, 'Rent Payment Recorded – ' . htmlspecialchars($propAddr), $html, $alt);
        }

        respond_json(true, 'Payment record submitted successfully! Pending admin confirmation.', ['payment_id' => $payment_id]);

    } catch (Exception $e) {
        $conn->rollback();
        foreach ($uploaded_files as $f) {
            $full_path = $project_root . '/' . $f['file_path'];
            if (file_exists($full_path)) @unlink($full_path);
        }
        throw $e;
    }

} catch (Exception $e) {
    error_log("Record rental payment error: " . $e->getMessage());
    respond_json(false, 'An error occurred. Please try again.');
}
?>
