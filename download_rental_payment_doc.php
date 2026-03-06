<?php
session_start();
require_once __DIR__ . '/config/session_timeout.php';
include 'connection.php';

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit();
}

$doc_id = isset($_GET['doc_id']) ? (int) $_GET['doc_id'] : 0;
if ($doc_id <= 0) {
    http_response_code(400);
    echo "Invalid document ID.";
    exit();
}

// Verify ownership or admin access
$stmt = $conn->prepare("
    SELECT rpd.*, rp.agent_id 
    FROM rental_payment_documents rpd 
    JOIN rental_payments rp ON rpd.payment_id = rp.payment_id 
    WHERE rpd.document_id = ?
");
$stmt->bind_param("i", $doc_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    http_response_code(404);
    echo "Document not found.";
    exit();
}

// Only the owning agent or admin may download
if ($_SESSION['user_role'] !== 'admin' && $_SESSION['account_id'] != $doc['agent_id']) {
    http_response_code(403);
    echo "Access denied.";
    exit();
}

$filepath = __DIR__ . '/' . ltrim(str_replace(['../', '..\\'], '', $doc['file_path']), '/');
if (!file_exists($filepath)) {
    http_response_code(404);
    echo "File not found on server.";
    exit();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filepath);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode(basename($doc['original_filename'])) . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');
readfile($filepath);
exit();
