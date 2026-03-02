<?php
session_start();
require_once __DIR__ . '/connection.php';

// Respond with JSON
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
    $admin_id = $_SESSION['account_id'];

    // Validate required fields
    $required_fields = ['property_id', 'sale_price', 'sale_date', 'buyer_name'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            respond_json(false, "Missing required field: $field");
        }
    }

    // Validate file upload
    if (!isset($_FILES['sale_documents']) || empty($_FILES['sale_documents']['name'][0])) {
        respond_json(false, 'At least one sale document is required.');
    }

    $property_id = intval($_POST['property_id']);
    $sale_price = floatval($_POST['sale_price']);
    $sale_date = $_POST['sale_date'];
    $buyer_name = trim($_POST['buyer_name']);
    $buyer_email = trim($_POST['buyer_email'] ?? '');
    $additional_notes = trim($_POST['additional_notes'] ?? '');

    // --- Validate property exists and is approved ---
    $property_check = $conn->prepare("
        SELECT p.property_ID, p.StreetAddress, p.City, p.PropertyType, p.Status, p.approval_status, p.ListingPrice
        FROM property p
        WHERE p.property_ID = ? AND p.approval_status = 'approved'
        LIMIT 1
    ");
    $property_check->bind_param("i", $property_id);
    $property_check->execute();
    $property_result = $property_check->get_result();

    if ($property_result->num_rows === 0) {
        respond_json(false, 'Property not found or not approved.');
    }

    $property = $property_result->fetch_assoc();
    $property_check->close();

    // Check if property is already sold
    if ($property['Status'] === 'Sold') {
        respond_json(false, 'This property is already marked as sold.');
    }

    // Check if there's already a pending or approved sale verification
    $existing_check = $conn->prepare("
        SELECT verification_id, status 
        FROM sale_verifications 
        WHERE property_id = ? AND status IN ('Pending', 'Approved')
    ");
    $existing_check->bind_param("i", $property_id);
    $existing_check->execute();
    $existing_result = $existing_check->get_result();

    if ($existing_result->num_rows > 0) {
        $existing = $existing_result->fetch_assoc();
        respond_json(false, "A sale verification is already {$existing['status']} for this property.");
    }
    $existing_check->close();

    // Validate sale date (not in the future)
    $sale_date_obj = DateTime::createFromFormat('Y-m-d', $sale_date);
    if (!$sale_date_obj || $sale_date_obj > new DateTime('now', new DateTimeZone('Asia/Manila'))) {
        respond_json(false, 'Invalid sale date. Date cannot be in the future.');
    }

    // Validate sale price
    if ($sale_price <= 0) {
        respond_json(false, 'Sale price must be greater than zero.');
    }

    // --- File Upload Processing ---
    $upload_base = __DIR__ . '/sale_documents';
    if (!file_exists($upload_base)) {
        if (!mkdir($upload_base, 0755, true)) {
            respond_json(false, 'Failed to create upload directory.');
        }
    }

    $upload_dir = $upload_base . '/' . $property_id;
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            respond_json(false, 'Failed to create property upload directory.');
        }
    }

    $uploaded_files = [];
    $allowed_types = [
        'application/pdf',
        'image/jpeg', 'image/jpg', 'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    $max_file_size = 120 * 1024 * 1024; // 120MB

    $files = $_FILES['sale_documents'];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $file_name = $files['name'][$i];
            $file_tmp = $files['tmp_name'][$i];
            $file_size = $files['size'][$i];
            $file_type = $files['type'][$i];

            if (!in_array($file_type, $allowed_types)) {
                respond_json(false, "Invalid file type: $file_name. Allowed: PDF, JPG, PNG, DOC, DOCX.");
            }

            if ($file_size > $max_file_size) {
                respond_json(false, "File too large: $file_name (max 120MB).");
            }

            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $unique_name = uniqid('admin_') . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . '/' . $unique_name;
            $relative_path = 'sale_documents/' . $property_id . '/' . $unique_name;

            if (move_uploaded_file($file_tmp, $file_path)) {
                $uploaded_files[] = [
                    'original_name' => $file_name,
                    'stored_name' => $unique_name,
                    'file_path' => $relative_path,
                    'file_size' => $file_size,
                    'mime_type' => $file_type
                ];
            } else {
                respond_json(false, "Failed to upload file: $file_name");
            }
        } else {
            respond_json(false, "Upload error for file: " . $files['name'][$i]);
        }
    }

    // --- Determine the original agent who created this listing ---
    $agent_id = null;
    $agent_stmt = $conn->prepare("
        SELECT pl.account_id, a.role_id 
        FROM property_log pl
        JOIN accounts a ON a.account_id = pl.account_id
        WHERE pl.property_id = ? AND pl.action = 'CREATED'
        ORDER BY pl.log_timestamp ASC LIMIT 1
    ");
    $agent_stmt->bind_param("i", $property_id);
    $agent_stmt->execute();
    $agent_row = $agent_stmt->get_result()->fetch_assoc();
    $agent_stmt->close();
    
    // Use the creator's ID; if the creator was an admin, use admin_id
    $agent_id = $agent_row ? (int)$agent_row['account_id'] : $admin_id;

    // --- Begin Transaction ---
    $conn->begin_transaction();

    try {
        // 1) Insert sale verification record (admin-submitted, status = Pending for review workflow)
        $insert_verification = $conn->prepare("
            INSERT INTO sale_verifications 
            (property_id, agent_id, sale_price, sale_date, buyer_name, buyer_email, additional_notes, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $insert_verification->bind_param(
            "iidssss",
            $property_id, $agent_id, $sale_price, $sale_date,
            $buyer_name, $buyer_email, $additional_notes
        );

        if (!$insert_verification->execute()) {
            throw new Exception('Failed to insert sale verification record.');
        }
        $verification_id = $conn->insert_id;
        $insert_verification->close();

        // 2) Update property status to 'Pending Sold'
        $update_property = $conn->prepare("UPDATE property SET Status = 'Pending Sold' WHERE property_ID = ?");
        $update_property->bind_param("i", $property_id);
        if (!$update_property->execute()) {
            throw new Exception('Failed to update property status.');
        }
        $update_property->close();

        // 3) Insert document records
        $insert_document = $conn->prepare("
            INSERT INTO sale_verification_documents 
            (verification_id, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($uploaded_files as $file) {
            $insert_document->bind_param(
                "isssis",
                $verification_id,
                $file['original_name'],
                $file['stored_name'],
                $file['file_path'],
                $file['file_size'],
                $file['mime_type']
            );

            if (!$insert_document->execute()) {
                throw new Exception('Failed to insert document record for: ' . $file['original_name']);
            }
        }
        $insert_document->close();

        // 4) Log in property_log
        $log_msg = 'Sale verification submitted by admin';
        $log_stmt = $conn->prepare("
            INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message, reference_id)
            VALUES (?, ?, 'UPDATED', NOW(), ?, ?)
        ");
        $log_stmt->bind_param("iisi", $property_id, $admin_id, $log_msg, $verification_id);
        $log_stmt->execute();
        $log_stmt->close();

        // 5) Status log entry (sale verification submission is tracked via property_log and sale_verifications table)

        // 6) Admin notification
        $notif_msg = "Sale verification submitted for Property #{$property_id} ({$property['StreetAddress']}, {$property['City']}). Sale price: ₱" . number_format($sale_price, 2);
        $n = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, action_url, icon, created_at)
                             VALUES (?, 'property_sale', 'Sale Verification Submitted', ?, 'update', 'high', ?, 'bi-check-circle', NOW())");
        $action_url = "admin_property_sale_approvals.php";
        $n->bind_param('iss', $verification_id, $notif_msg, $action_url);
        $n->execute();
        $n->close();

        // 7) Agent notification (if agent is different from admin)
        if ($agent_id !== $admin_id) {
            require_once __DIR__ . '/agent_pages/agent_notification_helper.php';
            createAgentNotification(
                $conn,
                $agent_id,
                'general',
                'Sale Verification Submitted',
                "A sale verification has been submitted for your property at {$property['StreetAddress']}, {$property['City']}. It is pending admin approval.",
                $verification_id
            );
        }

        $conn->commit();

        respond_json(true, 'Sale verification submitted successfully! The property is now marked as Pending Sold.', [
            'property_id' => $property_id,
            'verification_id' => $verification_id
        ]);

    } catch (\Throwable $e) {
        $conn->rollback();

        // Clean up uploaded files on error
        foreach ($uploaded_files as $file) {
            $abs_path = __DIR__ . '/' . $file['file_path'];
            if (file_exists($abs_path)) {
                unlink($abs_path);
            }
        }

        throw $e;
    }

} catch (\Throwable $e) {
    error_log("Admin sale verification error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    respond_json(false, 'An error occurred: ' . $e->getMessage());
}
?>
