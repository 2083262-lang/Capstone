<?php
session_start();
require_once __DIR__ . '/config/session_timeout.php';
header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'connection.php';

$action = $_POST['action'] ?? '';
$type   = $_POST['type'] ?? '';

if (!in_array($action, ['add', 'delete'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

if (!in_array($type, ['amenity', 'specialization', 'property_type'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid type']);
    exit();
}

// Determine table/column names
if ($type === 'amenity') {
    $table = 'amenities';
    $id_col = 'amenity_id';
    $name_col = 'amenity_name';
    $pivot_table = 'property_amenities';
    $pivot_fk = 'amenity_id';
    $label = 'Amenity';
} elseif ($type === 'property_type') {
    $table = 'property_types';
    $id_col = 'property_type_id';
    $name_col = 'type_name';
    $pivot_table = 'property';
    $pivot_fk = 'PropertyType';
    $label = 'Property Type';
} else {
    $table = 'specializations';
    $id_col = 'specialization_id';
    $name_col = 'specialization_name';
    $pivot_table = 'agent_specializations';
    $pivot_fk = 'specialization_id';
    $label = 'Specialization';
}

// ===== ADD =====
if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => $label . ' name is required.']);
        exit();
    }

    if (strlen($name) > 100) {
        echo json_encode(['success' => false, 'message' => $label . ' name must be 100 characters or less.']);
        exit();
    }

    // Allow standard characters: letters, numbers, spaces, hyphens, slashes, parentheses, ampersands
    if (!preg_match('/^[A-Za-z0-9\s\-\/\(\)&,\.]+$/', $name)) {
        echo json_encode(['success' => false, 'message' => $label . ' name contains invalid characters.']);
        exit();
    }

    // Check for duplicate (case-insensitive)
    $check = $conn->prepare("SELECT $id_col FROM $table WHERE LOWER($name_col) = LOWER(?) LIMIT 1");
    $check->bind_param("s", $name);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        echo json_encode(['success' => false, 'message' => $label . ' "' . $name . '" already exists.']);
        exit();
    }
    $check->close();

    // Insert
    $stmt = $conn->prepare("INSERT INTO $table ($name_col) VALUES (?)");
    $stmt->bind_param("s", $name);
    if ($stmt->execute()) {
        $new_id = (int)$conn->insert_id;
        $stmt->close();
        echo json_encode(['success' => true, 'message' => $label . ' added successfully.', 'id' => $new_id]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $err]);
    }
    exit();
}

// ===== DELETE =====
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        exit();
    }

    // Check it exists
    $check = $conn->prepare("SELECT $id_col FROM $table WHERE $id_col = ? LIMIT 1");
    $check->bind_param("i", $id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $check->close();
        echo json_encode(['success' => false, 'message' => $label . ' not found.']);
        exit();
    }
    $check->close();

    // Check usage count (for informational message)
    $usage = 0;
    if ($type === 'property_type') {
        // For property types, check how many properties use this type name
        $name_stmt = $conn->prepare("SELECT $name_col FROM $table WHERE $id_col = ? LIMIT 1");
        $name_stmt->bind_param("i", $id);
        $name_stmt->execute();
        $name_row = $name_stmt->get_result()->fetch_assoc();
        $name_stmt->close();
        $type_name_val = $name_row[$name_col] ?? '';
        
        $u_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM property WHERE PropertyType = ?");
        $u_stmt->bind_param("s", $type_name_val);
        $u_stmt->execute();
        $u_row = $u_stmt->get_result()->fetch_assoc();
        $usage = (int)$u_row['c'];
        $u_stmt->close();
    } else {
        $u_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM $pivot_table WHERE $pivot_fk = ?");
        $u_stmt->bind_param("i", $id);
        $u_stmt->execute();
        $u_row = $u_stmt->get_result()->fetch_assoc();
        $usage = (int)$u_row['c'];
        $u_stmt->close();
    }

    // Delete (CASCADE will handle pivot rows)
    $del = $conn->prepare("DELETE FROM $table WHERE $id_col = ?");
    $del->bind_param("i", $id);
    if ($del->execute()) {
        $del->close();
        $msg = $label . ' deleted successfully.';
        if ($usage > 0) {
            $msg .= " It was removed from $usage linked record(s).";
        }
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        $err = $del->error;
        $del->close();
        echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $err]);
    }
    exit();
}

$conn->close();
echo json_encode(['success' => false, 'message' => 'Unknown error']);
