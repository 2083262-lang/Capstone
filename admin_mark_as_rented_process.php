<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/config/session_timeout.php';

// JSON response helper
function respond_json($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra));
    exit();
}

// --- Security: Admin only ---
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    respond_json(false, 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(false, 'Invalid request method.');
}

try {
    $admin_id = (int) $_SESSION['account_id'];

    // ----- Validate required fields -----
    $required = ['property_id', 'tenant_name', 'monthly_rent', 'lease_start_date', 'lease_term_months'];
    foreach ($required as $f) {
        if (empty($_POST[$f])) {
            respond_json(false, "Missing required field: $f");
        }
    }

    // Validate at least one document
    if (!isset($_FILES['rental_documents']) || empty($_FILES['rental_documents']['name'][0])) {
        respond_json(false, 'At least one supporting document is required.');
    }

    // ----- Sanitize & validate inputs -----
    $property_id      = intval($_POST['property_id']);
    $tenant_name      = trim(strip_tags($_POST['tenant_name']));
    $tenant_email     = !empty($_POST['tenant_email']) ? trim($_POST['tenant_email']) : null;
    $tenant_phone     = !empty($_POST['tenant_phone']) ? trim(strip_tags($_POST['tenant_phone'])) : null;
    $monthly_rent     = floatval($_POST['monthly_rent']);
    $security_deposit = isset($_POST['security_deposit']) ? floatval($_POST['security_deposit']) : 0.00;
    $lease_start_date = $_POST['lease_start_date'];
    $lease_term_months = intval($_POST['lease_term_months']);
    $additional_notes = !empty($_POST['additional_notes']) ? trim(strip_tags(substr($_POST['additional_notes'], 0, 2000))) : null;

    if (strlen($tenant_name) < 1 || strlen($tenant_name) > 255) {
        respond_json(false, 'Tenant name must be 1-255 characters.');
    }
    if ($tenant_email !== null && !filter_var($tenant_email, FILTER_VALIDATE_EMAIL)) {
        respond_json(false, 'Invalid tenant email format.');
    }
    if ($tenant_phone !== null && strlen($tenant_phone) > 20) {
        respond_json(false, 'Tenant phone must be at most 20 characters.');
    }
    if ($monthly_rent <= 0 || $monthly_rent > 99999999.99) {
        respond_json(false, 'Monthly rent must be between 0.01 and 99,999,999.99.');
    }
    if ($security_deposit < 0) {
        respond_json(false, 'Security deposit cannot be negative.');
    }

    // Validate lease start date
    $start_dt = DateTime::createFromFormat('Y-m-d', $lease_start_date);
    if (!$start_dt) {
        respond_json(false, 'Invalid lease start date format.');
    }
    $thirty_days_ago = new DateTime('-30 days');
    if ($start_dt < $thirty_days_ago) {
        respond_json(false, 'Lease start date cannot be more than 30 days in the past.');
    }

    if ($lease_term_months < 1 || $lease_term_months > 120) {
        respond_json(false, 'Lease term must be between 1 and 120 months.');
    }

    // ----- Verify property exists and is approved for rent -----
    $prop_stmt = $conn->prepare("
        SELECT p.property_ID, p.StreetAddress, p.City, p.PropertyType, p.Status, p.approval_status, p.ListingPrice
        FROM property p
        WHERE p.property_ID = ? AND p.approval_status = 'approved'
        LIMIT 1
    ");
    $prop_stmt->bind_param("i", $property_id);
    $prop_stmt->execute();
    $prop_result = $prop_stmt->get_result();

    if ($prop_result->num_rows === 0) {
        respond_json(false, 'Property not found or not approved.');
    }
    $property = $prop_result->fetch_assoc();
    $prop_stmt->close();

    if ($property['Status'] !== 'For Rent') {
        respond_json(false, 'This property is not a rental listing. Current status: ' . $property['Status']);
    }

    // ----- Check no existing pending/approved rental verification -----
    $dup_stmt = $conn->prepare("
        SELECT verification_id FROM rental_verifications
        WHERE property_id = ? AND status IN ('Pending', 'Approved')
    ");
    $dup_stmt->bind_param("i", $property_id);
    $dup_stmt->execute();
    if ($dup_stmt->get_result()->num_rows > 0) {
        respond_json(false, 'A rental verification is already pending or approved for this property.');
    }
    $dup_stmt->close();

    // Find the agent who owns this property (for commission tracking)
    $agent_stmt = $conn->prepare("
        SELECT account_id FROM property_log
        WHERE property_id = ? AND action = 'CREATED'
        ORDER BY log_timestamp DESC LIMIT 1
    ");
    $agent_stmt->bind_param("i", $property_id);
    $agent_stmt->execute();
    $agent_result = $agent_stmt->get_result();
    $agent_row = $agent_result->fetch_assoc();
    $agent_id = $agent_row ? (int)$agent_row['account_id'] : $admin_id;
    $agent_stmt->close();

    // ----- File upload processing -----
    $upload_base = __DIR__ . '/rental_documents';
    if (!file_exists($upload_base)) {
        mkdir($upload_base, 0755, true);
    }
    $upload_dir = $upload_base . '/' . $property_id;
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $uploaded_files = [];
    $allowed_types  = [
        'application/pdf',
        'image/jpeg', 'image/jpg', 'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $max_file_size = 120 * 1024 * 1024; // 120 MB

    $files = $_FILES['rental_documents'];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            respond_json(false, 'Upload error for file: ' . basename($files['name'][$i]));
        }

        $orig_name = basename($files['name'][$i]);
        $tmp       = $files['tmp_name'][$i];
        $size      = $files['size'][$i];
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext)) {
            respond_json(false, "Invalid file type: $orig_name. Allowed: pdf, jpg, jpeg, png, doc, docx");
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp);
        if (!in_array($mime, $allowed_types)) {
            respond_json(false, "Invalid file content type for: $orig_name");
        }

        if ($size > $max_file_size) {
            respond_json(false, "File too large: $orig_name (max 120MB)");
        }

        $stored_name = uniqid('rd_', true) . '_' . time() . '.' . $ext;
        $dest_path   = $upload_dir . '/' . $stored_name;

        if (!move_uploaded_file($tmp, $dest_path)) {
            respond_json(false, "Failed to upload file: $orig_name");
        }

        // Store relative path for DB
        $relative_path = 'rental_documents/' . $property_id . '/' . $stored_name;

        $uploaded_files[] = [
            'original_name' => $orig_name,
            'stored_name'   => $stored_name,
            'file_path'     => $relative_path,
            'full_path'     => $dest_path,
            'file_size'     => $size,
            'mime_type'     => $mime
        ];
    }

    // ----- Transaction -----
    $conn->begin_transaction();

    try {
        // 1. Insert rental verification
        $ins = $conn->prepare("
            INSERT INTO rental_verifications
            (property_id, agent_id, monthly_rent, security_deposit, lease_start_date,
             lease_term_months, tenant_name, tenant_email, tenant_phone, additional_notes, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $ins->bind_param(
            "iiddsissss",
            $property_id, $agent_id, $monthly_rent, $security_deposit,
            $lease_start_date, $lease_term_months, $tenant_name,
            $tenant_email, $tenant_phone, $additional_notes
        );
        if (!$ins->execute()) {
            throw new Exception('Failed to insert rental verification: ' . $ins->error);
        }
        $verification_id = $conn->insert_id;
        $ins->close();

        // 2. Insert document records
        $doc_ins = $conn->prepare("
            INSERT INTO rental_verification_documents
            (verification_id, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        foreach ($uploaded_files as $f) {
            $doc_ins->bind_param(
                "isssis",
                $verification_id, $f['original_name'], $f['stored_name'],
                $f['file_path'], $f['file_size'], $f['mime_type']
            );
            if (!$doc_ins->execute()) {
                throw new Exception('Failed to insert document record for: ' . $f['original_name']);
            }
        }
        $doc_ins->close();

        // 3. Update property status to Pending Rented
        $upd = $conn->prepare("UPDATE property SET Status = 'Pending Rented' WHERE property_ID = ?");
        $upd->bind_param("i", $property_id);
        if (!$upd->execute()) {
            throw new Exception('Failed to update property status.');
        }
        $upd->close();

        // 4. Property log
        $log_msg = 'Rental verification submitted by admin';
        $log_stmt = $conn->prepare("
            INSERT INTO property_log (property_id, account_id, action, reason_message)
            VALUES (?, ?, 'UPDATED', ?)
        ");
        $log_stmt->bind_param("iis", $property_id, $admin_id, $log_msg);
        $log_stmt->execute();
        $log_stmt->close();

        // 5. Create admin notification
        $notif_title = 'Rental Verification Submitted';
        $notif_msg = "Rental verification submitted for Property #{$property_id} ({$property['StreetAddress']}, {$property['City']}). Tenant: $tenant_name, Monthly Rent: ₱" . number_format($monthly_rent, 2);
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (item_id, item_type, title, message, category, priority, action_url, icon, is_read, created_at)
            VALUES (?, 'property_rental', ?, ?, 'update', 'high', 'admin_rental_approvals.php', 'bi-house-check', 0, NOW())
        ");
        $notif_stmt->bind_param("iss", $verification_id, $notif_title, $notif_msg);
        $notif_stmt->execute();
        $notif_stmt->close();

        // 6. Notify the agent (if property was agent-created, not admin-created)
        if ($agent_id !== $admin_id) {
            $agent_notif_msg = "Admin submitted a rental verification for your property at {$property['StreetAddress']}, {$property['City']}. Tenant: $tenant_name.";
            $agent_notif_stmt = $conn->prepare("
                INSERT INTO agent_notifications (agent_account_id, notif_type, reference_id, title, message, is_read, created_at)
                VALUES (?, 'general', ?, 'Rental Verification Submitted', ?, 0, NOW())
            ");
            $agent_notif_stmt->bind_param("iis", $agent_id, $verification_id, $agent_notif_msg);
            $agent_notif_stmt->execute();
            $agent_notif_stmt->close();
        }

        $conn->commit();

        respond_json(true, 'Rental verification submitted successfully! The submission is pending review.', [
            'property_id'     => $property_id,
            'verification_id' => $verification_id
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        // Clean up uploaded files on failure
        foreach ($uploaded_files as $f) {
            if (file_exists($f['full_path'])) {
                @unlink($f['full_path']);
            }
        }
        throw $e;
    }

} catch (Exception $e) {
    error_log("Admin rental verification error: " . $e->getMessage());
    respond_json(false, 'An error occurred while processing the rental verification. Please try again.');
}
?>
