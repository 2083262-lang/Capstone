<?php
// Endpoint to generate and send a 2FA email code
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['pending_login']) || !is_array($_SESSION['pending_login'])) {
    echo json_encode(['success' => false, 'message' => 'No pending login.']);
    exit();
}

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/mail_helper.php';

$pending = $_SESSION['pending_login'];
$accountId = (int) $pending['account_id'];
$toEmail = $pending['email'] ?? null;
$toName = trim(($pending['first_name'] ?? '') . ' ' . ($pending['last_name'] ?? '')) ?: ($pending['username'] ?? 'User');

if (!$toEmail) {
    echo json_encode(['success' => false, 'message' => 'Missing recipient email.']);
    exit();
}

// Throttle: small server-side safety (3s) to prevent double-clicks; expire codes after 60 seconds
$throttleSeconds = 3;
$ttlSeconds = 60;

// Ensure table exists (optional lightweight guard; comment out in production if managed by migrations)
// $conn->query("CREATE TABLE IF NOT EXISTS two_factor_codes (
//   code_id INT AUTO_INCREMENT PRIMARY KEY,
//   account_id INT NOT NULL,
//   code_hash VARCHAR(255) NOT NULL,
//   expires_at DATETIME NOT NULL,
//   attempts INT NOT NULL DEFAULT 0,
//   consumed TINYINT(1) NOT NULL DEFAULT 0,
//   delivery VARCHAR(32) NOT NULL DEFAULT 'email',
//   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   INDEX idx_account_expires (account_id, expires_at)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

// Optional throttle disabled (client enforces cooldown). If you want to enable server throttle,
// set $throttleSeconds > 0 and re-enable this block.
if ($throttleSeconds > 0) {
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
}

// Generate a 6-digit numeric code
$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$expiresAtSeconds = (int)$ttlSeconds; // Use DB NOW() for expiry

// Invalidate any previously active codes for this account
$invalidate = $conn->prepare("UPDATE two_factor_codes SET consumed = 1 WHERE account_id = ? AND consumed = 0");
$invalidate->bind_param('i', $accountId);
$invalidate->execute();
$invalidate->close();

// Persist the new code; compute expiry using DB clock
$ins = $conn->prepare("INSERT INTO two_factor_codes (account_id, code_hash, expires_at, attempts, consumed, delivery) 
                       VALUES (?, ?, DATE_ADD(NOW(), INTERVAL $expiresAtSeconds SECOND), 0, 0, 'email')");
$ins->bind_param('is', $accountId, $codeHash);
$ok = $ins->execute();
$ins->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Could not create code.']);
    exit();
}

// Compose email with modern minimalist design (Gold, Black, Blue)
$subject = 'Your Verification Code';
$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Code</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    
    <!-- Email Container -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                
                <!-- Content Card -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    
                    <!-- Gold Accent Line -->
                    <tr>
                        <td style="background:linear-gradient(90deg,#d4af37 0%,#f4d03f 50%,#d4af37 100%);height:3px;"></td>
                    </tr>
                    
                    <!-- Header -->
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <h1 style="margin:0 0 12px 0;color:#d4af37;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Verification Required</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Enter the code below to continue</p>
                        </td>
                    </tr>
                    
                    <!-- Body Content -->
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            
                            <!-- Verification Code Display -->
                            <div style="text-align:center;margin:0 0 40px 0;">
                                <div style="display:inline-block;background-color:#0d1117;border:1px solid #2563eb;border-radius:2px;padding:28px 40px;">
                                    <p style="margin:0 0 12px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:#2563eb;">Your Code</p>
                                    <div style="font-size:42px;font-weight:700;letter-spacing:12px;color:#ffffff;font-family:\'SF Mono\',\'Courier New\',monospace;">
                                        ' . htmlspecialchars($code) . '
                                    </div>
                                    <p style="margin:12px 0 0 0;font-size:12px;color:#666666;">Expires in ' . (int)$ttlSeconds . ' seconds</p>
                                </div>
                            </div>
                            
                            <!-- Divider -->
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            
                            <!-- Instructions -->
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;text-align:center;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($toName) . '</span>, we received a sign-in request for your account. Enter the code above to verify your identity.
                            </p>
                            
                            <!-- Security Notice -->
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:16px 20px;margin:0 0 32px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#d4af37;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Security Notice</strong>
                                    Never share this code. Our team will never ask for your verification code via email, phone, or any other method.
                                </p>
                            </div>
                            
                            <!-- Footer Message -->
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                If you didn\'t request this code, you can safely ignore this email.
                            </p>
                            
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px 0;font-size:13px;color:#666666;">
                                            <strong style="color:#d4af37;">Real Estate System</strong>
                                        </p>
                                        <p style="margin:0;font-size:11px;color:#444444;">
                                            © ' . date('Y') . ' All rights reserved
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                </table>
                
                <!-- Support Link -->
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;margin-top:32px;">
                    <tr>
                        <td style="text-align:center;">
                            <p style="margin:0;font-size:12px;color:#444444;">
                                Need assistance? <a href="#" style="color:#2563eb;text-decoration:none;font-weight:500;">Contact Support</a>
                            </p>
                        </td>
                    </tr>
                </table>
                
            </td>
        </tr>
    </table>
    
</body>
</html>
';

$result = sendSystemMail($toEmail, $toName, $subject, $html);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => 'Failed to send email.']);
    exit();
}

// In development, optionally return the code for quick testing (never enable in production)
echo json_encode(['success' => true]);
exit();
