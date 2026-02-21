<?php
session_start();

if (!isset($_SESSION['pending_login']) || !is_array($_SESSION['pending_login'])) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: two_factor.php');
    exit();
}

require_once __DIR__ . '/connection.php';

$pending = $_SESSION['pending_login'];
$accountId = (int) $pending['account_id'];
$role = $pending['user_role'] ?? '';
$redirectTo = $pending['redirect_to'] ?? 'login.php';
$rawCode = isset($_POST['code']) ? trim($_POST['code']) : '';
// Sanitize to digits only to prevent hidden whitespace/characters
$code = preg_replace('/\D+/', '', $rawCode);

// Debug log
error_log("[2FA DEBUG] Verifying code for account {$accountId}, role: {$role}, raw input: '{$rawCode}', sanitized: '{$code}', redirect: {$redirectTo}");

if (!preg_match('/^\d{6}$/', $code)) {
    $_SESSION['twofa_error'] = 'Invalid code format.';
    header('Location: two_factor.php');
    exit();
}

// Fetch the most recent unconsumed code regardless of expiry to give precise feedback
$stmt = $conn->prepare("SELECT code_id, code_hash, expires_at, attempts FROM two_factor_codes 
    WHERE account_id = ? AND consumed = 0 
    ORDER BY code_id DESC LIMIT 1");
$stmt->bind_param('i', $accountId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if ($res->num_rows === 0) {
    $_SESSION['twofa_error'] = 'No verification code found. Please click Resend to request a new code.';
    header('Location: two_factor.php');
    exit();
}

$latest = $res->fetch_assoc();

// Check expiry first for clear UX; do not auto-resend here
$isExpired = strtotime($latest['expires_at']) < time();
if ($isExpired) {
    $_SESSION['twofa_error'] = 'This verification code has expired. Please click Resend to get a new code.';
    header('Location: two_factor.php');
    exit();
}

// Rate limit incorrect attempts
if ((int)$latest['attempts'] >= 5) {
    $_SESSION['twofa_error'] = 'Too many incorrect attempts. Please click Resend to request a new code.';
    header('Location: two_factor.php');
    exit();
}

$matchedId = password_verify($code, $latest['code_hash']) ? (int)$latest['code_id'] : null;

if ($matchedId === null) {
    // Bump attempts on the latest only
    $bump = $conn->prepare("UPDATE two_factor_codes SET attempts = attempts + 1 WHERE code_id = ?");
    $cid = (int)$latest['code_id'];
    $bump->bind_param('i', $cid);
    $bump->execute();
    $bump->close();
    // Debug log
    error_log("[2FA DEBUG] Code mismatch for account {$accountId}. Input: {$code}, Latest code_id: {$cid}");
    $_SESSION['twofa_error'] = 'Incorrect code. Please try again or click Resend to request a new code.';
    header('Location: two_factor.php');
    exit();
}

// Success: atomically consume the matched code only if still unexpired and unconsumed
$upd = $conn->prepare("UPDATE two_factor_codes SET consumed = 1 
                       WHERE code_id = ? AND consumed = 0 AND expires_at >= NOW()");
$upd->bind_param('i', $matchedId);
$upd->execute();
$rows = $upd->affected_rows;
$upd->close();

if ($rows !== 1) {
    // Code either expired just now or already consumed; do not allow login
    $_SESSION['twofa_error'] = 'This verification code has expired. Please click Resend to get a new code.';
    header('Location: two_factor.php');
    exit();
}

// Debug log
error_log("[2FA DEBUG] Code verified for account {$accountId}. Matched code_id: {$matchedId}, redirecting to: {$redirectTo}");

// Promote session to fully authenticated
$_SESSION['account_id'] = $pending['account_id'];
$_SESSION['username'] = $pending['username'];
$_SESSION['user_role'] = $pending['user_role'];
$_SESSION['2fa_verified_at'] = date('c');

// Clear pending
unset($_SESSION['pending_login']);

// Log admin login here (post-2FA)
if ($role === 'admin') {
    $admin_id = (int) $_SESSION['account_id'];
    $log_stmt = $conn->prepare("INSERT INTO admin_logs (admin_account_id, action, action_type) VALUES (?, 'login', 'login')");
    $log_stmt->bind_param('i', $admin_id);
    $log_stmt->execute();
    $log_stmt->close();
    error_log("[2FA DEBUG] Admin login logged for account {$admin_id}");
}

header('Location: ' . $redirectTo);
exit();
