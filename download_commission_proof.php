<?php
/**
 * download_commission_proof.php
 *
 * Secure download handler for commission payment proof files.
 * - Admin: can download any commission proof
 * - Agent: can only download proof for their OWN commissions
 */

session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/config/session_timeout.php';

// Must be logged in
if (!isset($_SESSION['account_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit;
}

$user_role  = $_SESSION['user_role'] ?? '';
$account_id = (int) $_SESSION['account_id'];

// Only admin and agent roles are allowed
if (!in_array($user_role, ['admin', 'agent'], true)) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit;
}

// Validate commission_id parameter
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Invalid commission ID';
    exit;
}

$commission_id = (int) $_GET['id'];

// Fetch commission proof information
$sql = "
    SELECT ac.commission_id, ac.agent_id, ac.payment_proof_path,
           ac.payment_proof_original_name, ac.payment_proof_mime, ac.payment_proof_size,
           ac.status
    FROM agent_commissions ac
    WHERE ac.commission_id = ?
    AND ac.payment_proof_path IS NOT NULL
    AND ac.status = 'paid'
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $commission_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('HTTP/1.1 404 Not Found');
    echo 'Payment proof not found';
    exit;
}

$commission = $result->fetch_assoc();
$stmt->close();

// Agent ownership check: agents can only download their own commission proofs
if ($user_role === 'agent' && (int)$commission['agent_id'] !== $account_id) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied — you can only view your own commission proofs';
    exit;
}

// Build full file path
$relative_path = $commission['payment_proof_path'];
$full_path     = __DIR__ . '/' . $relative_path;

// Prevent path traversal
$real_base = realpath(__DIR__ . '/uploads/commission_proofs');
$real_file = realpath($full_path);

if ($real_file === false || $real_base === false || strpos($real_file, $real_base) !== 0) {
    header('HTTP/1.1 404 Not Found');
    echo 'File not found on server';
    exit;
}

// Check if file exists
if (!file_exists($full_path) || !is_file($full_path)) {
    header('HTTP/1.1 404 Not Found');
    echo 'File not found on server';
    exit;
}

// Determine safe filename for download
$download_name = $commission['payment_proof_original_name'];
if (empty($download_name)) {
    $download_name = 'commission_proof_' . $commission_id . '.' . pathinfo($full_path, PATHINFO_EXTENSION);
}

// Sanitize download filename
$download_name = preg_replace('/[^\w\-\.\s]/', '_', $download_name);

// Determine MIME type
$mime = $commission['payment_proof_mime'];
if (empty($mime)) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($full_path);
}

// Set headers for file download
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . ($commission['payment_proof_size'] ?: filesize($full_path)));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

// Clear output buffer
if (ob_get_level()) {
    ob_clean();
}

// Read and output file
readfile($full_path);
exit;
