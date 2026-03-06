<?php
session_start();
require_once 'connection.php';
require_once __DIR__ . '/config/session_timeout.php';

// Admin-only access
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit;
}

// Check if document_id is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Invalid document ID';
    exit;
}

$document_id = (int)$_GET['id'];
$type = isset($_GET['type']) ? $_GET['type'] : 'sale_verification';

// Build query based on document type
if ($type === 'rental_verification') {
    $sql = "
        SELECT rvd.original_filename, rvd.file_path, rvd.file_size, rvd.mime_type
        FROM rental_verification_documents rvd
        JOIN rental_verifications rv ON rvd.verification_id = rv.verification_id
        WHERE rvd.document_id = ?
    ";
} else {
    // Default: sale verification documents
    $sql = "
        SELECT svd.original_filename, svd.file_path, svd.file_size, svd.mime_type
        FROM sale_verification_documents svd
        JOIN sale_verifications sv ON svd.verification_id = sv.verification_id
        WHERE svd.document_id = ?
    ";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $document_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('HTTP/1.1 404 Not Found');
    echo 'Document not found';
    exit;
}

$document = $result->fetch_assoc();
$stmt->close();

// Convert relative path to absolute file system path
$file_path = $document['file_path'];
if ($type === 'rental_verification') {
    $file_path = str_replace('../rental_documents/', 'rental_documents/', $file_path);
} else {
    $file_path = str_replace('../sale_documents/', 'sale_documents/', $file_path);
}
$full_path = __DIR__ . '/' . $file_path;

// Check if file exists
if (!file_exists($full_path)) {
    header('HTTP/1.1 404 Not Found');
    echo 'File not found on server';
    exit;
}

// Set headers for file download
header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: attachment; filename="' . rawurlencode(basename($document['original_filename'])) . '"');
header('Content-Length: ' . $document['file_size']);
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Clear output buffer
if (ob_get_level()) {
    ob_clean();
}

// Read and output file
readfile($full_path);
exit;
?>