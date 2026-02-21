<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/config/mail_config.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

/**
 * Send an email with production-safe settings.
 * - STARTTLS on port 587 only
 * - No verbose SMTP details returned to the client
 * - Optional server-side logging only
 */
function sendSystemMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): array {
    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
    // Use hostname to match TLS certificate CN (avoid CN mismatch)
    $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USERNAME;
        $mail->Password   = MAIL_SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 'tls'
        $mail->Port       = (int) MAIL_SMTP_PORT;
        $mail->AuthType   = 'LOGIN';
    // If your environment struggles with IPv6, you can force IPv4 at the OS level.
    // PHPMailer doesn't expose a direct IPv4-only toggle without breaking TLS CN checks.

        // No verbose debug in client responses; optional server log only
    // Use SMTP debug level constants (off/server)
    $mail->SMTPDebug  = MAIL_DEBUG_ENABLED ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
        if (MAIL_DEBUG_ENABLED && MAIL_LOG_FILE) {
            $logFile = MAIL_LOG_FILE;
            $mail->Debugoutput = function ($str, $level) use ($logFile) {
                @error_log("PHPMailer[$level]: $str\n", 3, $logFile);
            };
        }

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM_EMAIL, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        if (MAIL_LOG_FILE) {
            @error_log('[MAIL ERROR] ' . ($mail->ErrorInfo ?: $e->getMessage()) . "\n", 3, MAIL_LOG_FILE);
        }
        return ['success' => false];
    }
}

/**
 * Backwards-compatible wrapper used by legacy pages.
 * Delegates to sendSystemMail while allowing the simpler signature.
 *
 * @param string $toEmail   Recipient email address
 * @param string $subject   Email subject
 * @param string $htmlBody  HTML body content
 * @param string $toName    Optional recipient name
 * @return bool             True on success, false on failure
 */
function sendEmail(string $toEmail, string $subject, string $htmlBody, string $toName = ''): bool {
    $result = sendSystemMail($toEmail, $toName, $subject, $htmlBody);
    return (bool)($result['success'] ?? false);
}
