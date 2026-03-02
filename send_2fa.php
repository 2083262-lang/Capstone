<?php
// ═══════════════════════════════════════════════════════════════════
// Endpoint: Generate and send a 2FA email verification code
// Security: CSRF token, POST-only, server-side cooldown, per-account
//           send-rate limit, pending-login expiration, code cleanup
// ═══════════════════════════════════════════════════════════════════
session_start();

// ── Security headers ──
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// ── POST method required ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

// ── Session guard ──
if (!isset($_SESSION['pending_login']) || !is_array($_SESSION['pending_login'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No pending login.']);
    exit();
}

// ── CSRF token validation ──
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['twofa_csrf_token']) || !hash_equals($_SESSION['twofa_csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh the page.']);
    exit();
}

// ── Pending-login expiration check (max 10 min from login) ──
$pendingAge = time() - ($_SESSION['pending_login']['created_at'] ?? 0);
if ($pendingAge > 600) { // 10 minutes
    unset($_SESSION['pending_login'], $_SESSION['twofa_csrf_token'], $_SESSION['twofa_init_sent']);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.', 'expired' => true]);
    exit();
}

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/mail_helper.php';

$pending   = $_SESSION['pending_login'];
$accountId = (int) $pending['account_id'];
$toEmail   = $pending['email'] ?? null;
$toName    = trim(($pending['first_name'] ?? '') . ' ' . ($pending['last_name'] ?? '')) ?: ($pending['username'] ?? 'User');

if (!$toEmail) {
    echo json_encode(['success' => false, 'message' => 'Missing recipient email.']);
    exit();
}

// ═══════════════════════════════════════════════
// Security constants
// ═══════════════════════════════════════════════
$throttleSeconds   = 60;   // Min seconds between code sends (server-enforced)
$ttlSeconds        = 300;  // Code valid for 5 minutes (NIST SP 800-63B recommendation for email OTP)
$maxSendsPerWindow = 5;    // Max code sends per account per 15-minute window
$sendWindowSeconds = 900;  // 15-minute window for send rate limiting

// ═══════════════════════════════════════════════
// Server-side cooldown: prevent rapid resends
// ═══════════════════════════════════════════════
$stmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds 
                        FROM two_factor_codes 
                        WHERE account_id = ? AND consumed = 0 
                        ORDER BY code_id DESC LIMIT 1");
$stmt->bind_param('i', $accountId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if ($row = $res->fetch_assoc()) {
    $age = is_null($row['age_seconds']) ? $throttleSeconds : (int)$row['age_seconds'];
    if ($age < $throttleSeconds) {
        echo json_encode(['success' => false, 'retryAfter' => $throttleSeconds - $age]);
        exit();
    }
}

// ═══════════════════════════════════════════════
// Per-account send-rate limiting: max N codes per window
// Prevents email bombing and code-generation abuse
// ═══════════════════════════════════════════════
$rateStmt = $conn->prepare("SELECT COUNT(*) AS send_count FROM two_factor_codes 
                            WHERE account_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)");
$rateStmt->bind_param('ii', $accountId, $sendWindowSeconds);
$rateStmt->execute();
$rateRow = $rateStmt->get_result()->fetch_assoc();
$rateStmt->close();

if ((int)($rateRow['send_count'] ?? 0) >= $maxSendsPerWindow) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many code requests. Please wait before trying again.']);
    exit();
}

// ═══════════════════════════════════════════════
// Generate secure 6-digit code
// ═══════════════════════════════════════════════
$code     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = password_hash($code, PASSWORD_DEFAULT);

// Invalidate any previously active codes for this account
$invalidate = $conn->prepare("UPDATE two_factor_codes SET consumed = 1 WHERE account_id = ? AND consumed = 0");
$invalidate->bind_param('i', $accountId);
$invalidate->execute();
$invalidate->close();

// Cleanup old expired/consumed codes (older than 24 hours) to prevent table bloat
$cleanup = $conn->prepare("DELETE FROM two_factor_codes WHERE account_id = ? AND (consumed = 1 OR expires_at < NOW()) AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$cleanup->bind_param('i', $accountId);
$cleanup->execute();
$cleanup->close();

// Persist the new code; compute expiry using DB clock
$ins = $conn->prepare("INSERT INTO two_factor_codes (account_id, code_hash, expires_at, attempts, consumed, delivery) 
                       VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0, 0, 'email')");
$ins->bind_param('isi', $accountId, $codeHash, $ttlSeconds);
$ok = $ins->execute();
$ins->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Could not create code.']);
    exit();
}

// Compose email using centralized template
require_once __DIR__ . '/email_template.php';

$subject = 'Your Verification Code';
$expiresLabel = (int)$ttlSeconds >= 60
    ? (int)($ttlSeconds / 60) . ' minute' . ((int)($ttlSeconds / 60) !== 1 ? 's' : '')
    : (int)$ttlSeconds . ' seconds';

$bodyContent  = emailStatusBadge('Your Code', htmlspecialchars($code), '#2563eb', 'Expires in ' . $expiresLabel);
$bodyContent .= emailDivider();
$bodyContent .= emailParagraph(
    'Hello <span style="color:#d4af37;font-weight:600;">' . htmlspecialchars($toName) . '</span>, we received a sign-in request for your account. Enter the code above to verify your identity.',
    true
);
$bodyContent .= emailNotice(
    'Security Notice',
    'Never share this code. Our team will never ask for your verification code via email, phone, or any other method.',
    '#d4af37'
);
$bodyContent .= emailClosing('If you didn\'t request this code, you can safely ignore this email.');

$html = buildEmailTemplate([
    'accentColor' => '#d4af37',
    'heading'     => 'Verification Required',
    'subtitle'    => 'Enter the code below to continue',
    'body'        => $bodyContent,
]);

$result = sendSystemMail($toEmail, $toName, $subject, $html);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => 'Failed to send email.']);
    exit();
}

// In development, optionally return the code for quick testing (never enable in production)
echo json_encode(['success' => true, 'ttl' => $ttlSeconds]);
exit();
