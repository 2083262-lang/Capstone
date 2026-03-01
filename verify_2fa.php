<?php
// ═══════════════════════════════════════════════════════════════════
// Endpoint: Verify a 2FA code and promote session to authenticated
// Security: CSRF, POST-only, session regeneration, redirect validation,
//           per-code + account-level rate limiting, pending expiration
// ═══════════════════════════════════════════════════════════════════
session_start();

// ── Security headers ──
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// Detect AJAX/JSON request
$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

function jsonError(string $msg, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

function jsonSuccess(string $redirect): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'redirect' => $redirect]);
    exit();
}

// ── Session guard ──
if (!isset($_SESSION['pending_login']) || !is_array($_SESSION['pending_login'])) {
    if ($isAjax) jsonError('Session expired. Please log in again.', 401);
    header('Location: login.php');
    exit();
}

// ── POST method required ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) jsonError('Method not allowed.', 405);
    header('Location: two_factor.php');
    exit();
}

// ── CSRF token validation ──
$csrfToken = '';
if ($isAjax) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
} else {
    $csrfToken = $_POST['csrf_token'] ?? '';
}
if (empty($_SESSION['twofa_csrf_token']) || !hash_equals($_SESSION['twofa_csrf_token'], $csrfToken)) {
    if ($isAjax) jsonError('Invalid request token. Please refresh the page.', 403);
    $_SESSION['twofa_error'] = 'Invalid request token. Please refresh the page.';
    header('Location: two_factor.php');
    exit();
}

// ── Pending-login expiration check (max 10 minutes) ──
$pendingAge = time() - ($_SESSION['pending_login']['created_at'] ?? 0);
if ($pendingAge > 600) { // 10 minutes
    unset($_SESSION['pending_login'], $_SESSION['twofa_csrf_token'], $_SESSION['twofa_init_sent']);
    if ($isAjax) jsonError('Session expired. Please log in again.', 401);
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/connection.php';

$pending    = $_SESSION['pending_login'];
$accountId  = (int) $pending['account_id'];
$role       = $pending['user_role'] ?? '';
$redirectTo = $pending['redirect_to'] ?? 'login.php';

// ── Redirect validation: must be a safe relative path ──
// Block absolute URLs, protocol-relative (//), backslash tricks, and data: URIs
if (
    preg_match('#^(https?://|//|\\\\|data:)#i', $redirectTo) ||
    strpos($redirectTo, "\0") !== false
) {
    $redirectTo = 'login.php';
}
// Whitelist: only allow known safe dashboard/profile paths
$allowedRedirects = [
    'admin_dashboard.php',
    'agent_pages/agent_dashboard.php',
    'agent_info_form.php',
    'login.php'
];
if (!in_array($redirectTo, $allowedRedirects, true)) {
    $redirectTo = 'login.php';
}

$rawCode = isset($_POST['code']) ? trim($_POST['code']) : '';
// Sanitize to digits only to prevent hidden whitespace/characters
$code = preg_replace('/\D+/', '', $rawCode);

// Server-side logging (sanitized — never log actual codes)
error_log("[2FA] Verification attempt for account {$accountId}, role: {$role}");

if (!preg_match('/^\d{6}$/', $code)) {
    if ($isAjax) jsonError('Invalid code format.');
    $_SESSION['twofa_error'] = 'Invalid code format.';
    header('Location: two_factor.php');
    exit();
}

// ═══════════════════════════════════════════════
// Account-level rate limiting: total failed attempts across ALL codes
// in the last 15 minutes. Prevents resend+retry brute-force loops.
// ═══════════════════════════════════════════════
$accountRateStmt = $conn->prepare("SELECT COALESCE(SUM(attempts), 0) AS total_attempts 
    FROM two_factor_codes 
    WHERE account_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
$accountRateStmt->bind_param('i', $accountId);
$accountRateStmt->execute();
$accountRateRow = $accountRateStmt->get_result()->fetch_assoc();
$accountRateStmt->close();

if ((int)($accountRateRow['total_attempts'] ?? 0) >= 15) {
    $msg = 'Too many failed verification attempts. Please wait 15 minutes before trying again.';
    if ($isAjax) jsonError($msg, 429);
    $_SESSION['twofa_error'] = $msg;
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
    if ($isAjax) jsonError('No verification code found. Please click Resend to request a new code.');
    $_SESSION['twofa_error'] = 'No verification code found. Please click Resend to request a new code.';
    header('Location: two_factor.php');
    exit();
}

$latest = $res->fetch_assoc();

// Check expiry first for clear UX
$isExpired = strtotime($latest['expires_at']) < time();
if ($isExpired) {
    if ($isAjax) jsonError('This verification code has expired. Please click Resend to get a new code.');
    $_SESSION['twofa_error'] = 'This verification code has expired. Please click Resend to get a new code.';
    header('Location: two_factor.php');
    exit();
}

// Per-code attempt limit
if ((int)$latest['attempts'] >= 5) {
    if ($isAjax) jsonError('Too many incorrect attempts. Please click Resend to request a new code.');
    $_SESSION['twofa_error'] = 'Too many incorrect attempts. Please click Resend to request a new code.';
    header('Location: two_factor.php');
    exit();
}

// ── Constant-time hash verification ──
$matchedId = password_verify($code, $latest['code_hash']) ? (int)$latest['code_id'] : null;

if ($matchedId === null) {
    // Bump attempts on the latest only
    $bump = $conn->prepare("UPDATE two_factor_codes SET attempts = attempts + 1 WHERE code_id = ?");
    $cid = (int)$latest['code_id'];
    $bump->bind_param('i', $cid);
    $bump->execute();
    $bump->close();

    error_log("[2FA] Code mismatch for account {$accountId}. code_id: {$cid}");

    if ($isAjax) jsonError('Incorrect code. Please try again or click Resend to request a new code.');
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
    if ($isAjax) jsonError('This verification code has expired. Please click Resend to get a new code.');
    $_SESSION['twofa_error'] = 'This verification code has expired. Please click Resend to get a new code.';
    header('Location: two_factor.php');
    exit();
}

error_log("[2FA] Code verified for account {$accountId}, redirecting to: {$redirectTo}");

// ═══════════════════════════════════════════════
// SESSION FIXATION PROTECTION (OWASP A07:2021)
// Regenerate session ID after privilege escalation
// Old session file is destroyed; cookie is updated
// ═══════════════════════════════════════════════
session_regenerate_id(true);

// Promote session to fully authenticated
$_SESSION['account_id']      = $pending['account_id'];
$_SESSION['username']        = $pending['username'];
$_SESSION['user_role']       = $pending['user_role'];
$_SESSION['2fa_verified_at'] = date('c');
$_SESSION['ip_address']      = $_SERVER['REMOTE_ADDR'] ?? '';
$_SESSION['user_agent']      = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Clear 2FA-specific session data
unset($_SESSION['pending_login'], $_SESSION['twofa_csrf_token'], $_SESSION['twofa_init_sent'], $_SESSION['twofa_error']);

// Log admin login here (post-2FA)
if ($role === 'admin') {
    $admin_id = (int) $_SESSION['account_id'];
    $log_stmt = $conn->prepare("INSERT INTO admin_logs (admin_account_id, action, action_type) VALUES (?, 'login', 'login')");
    $log_stmt->bind_param('i', $admin_id);
    $log_stmt->execute();
    $log_stmt->close();
}

if ($isAjax) jsonSuccess($redirectTo);
header('Location: ' . $redirectTo);
exit();
