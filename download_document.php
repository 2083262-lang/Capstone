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

// Fetch document information
$sql = "
    SELECT svd.*, sv.verification_id, sv.status
    FROM sale_verification_documents svd
    JOIN sale_verifications sv ON svd.verification_id = sv.verification_id
    WHERE svd.document_id = ?
";

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
$file_path = str_replace('../sale_documents/', 'sale_documents/', $document['file_path']);
$full_path = __DIR__ . '/' . $file_path;

// Check if file exists
if (!file_exists($full_path)) {
    header('HTTP/1.1 404 Not Found');
    echo 'File not found on server';
    exit;
}

// Set headers for file download
header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: attachment; filename="' . $document['original_filename'] . '"');
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