<?php
/**
 * Centralized email template builder for HomeEstate Realty.
 * 
 * Provides a consistent, wider (680px) dark-themed layout across
 * all transactional emails: 2FA, tour notifications, agent reviews,
 * sale approvals, etc.
 *
 * Usage:
 *   require_once __DIR__ . '/email_template.php';
 *   $html = buildEmailTemplate([
 *       'accentColor' => '#22c55e',          // top bar color
 *       'heading'     => 'Tour Confirmed',
 *       'subtitle'    => 'Your property tour has been approved',
 *       'body'        => '<p>...</p>',        // inner HTML content
 *       'footerExtra' => 'Cagayan De Oro ...' // optional extra footer line
 *   ]);
 */

function buildEmailTemplate(array $opts): string {
    $accent   = $opts['accentColor'] ?? '#d4af37';
    $heading  = htmlspecialchars($opts['heading']  ?? '');
    $subtitle = htmlspecialchars($opts['subtitle'] ?? '');
    $body     = $opts['body'] ?? '';
    $footerExtra = $opts['footerExtra'] ?? '';
    $year = date('Y');

    $footerExtraHtml = '';
    if ($footerExtra) {
        $footerExtraHtml = '<p style="margin:0 0 4px 0;font-size:11px;color:#555555;">' . htmlspecialchars($footerExtra) . '</p>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $heading . '</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0a0a0a;padding:40px 16px;">
        <tr>
            <td align="center">

                <!-- Main Card -->
                <table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:680px;width:100%;">

                    <!-- Accent Bar -->
                    <tr>
                        <td style="background:linear-gradient(90deg,' . $accent . ' 0%,' . $accent . ' 100%);height:3px;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    <!-- Header -->
                    <tr>
                        <td style="padding:36px 40px 24px 40px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <h1 style="margin:0 0 8px 0;color:' . $accent . ';font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:3px;">' . $heading . '</h1>
                            <p style="margin:0;color:#777777;font-size:14px;font-weight:400;">' . $subtitle . '</p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:36px 40px 32px 40px;">
                            ' . $body . '
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#0a0a0a;padding:24px 40px;border-top:1px solid #1f1f1f;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 6px 0;font-size:13px;color:#777777;">
                                            <strong style="color:#d4af37;">HomeEstate Realty</strong>
                                        </p>
                                        ' . $footerExtraHtml . '
                                        <p style="margin:0;font-size:11px;color:#555555;">
                                            &copy; ' . $year . ' All rights reserved.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>';
}

/* ── Reusable fragment helpers ───────────────────────────── */

/**
 * Greeting line: "Hello <name>," (or custom salutation)
 */
function emailGreeting(string $name, string $salutation = 'Hello'): string {
    return '<p style="margin:0 0 20px 0;font-size:14px;color:#999999;line-height:1.7;">
        ' . htmlspecialchars($salutation) . ' <span style="color:#d4af37;font-weight:600;">' . htmlspecialchars($name) . '</span>,
    </p>';
}

/**
 * Body paragraph.
 */
function emailParagraph(string $text, bool $centered = false): string {
    $align = $centered ? 'text-align:center;' : '';
    return '<p style="margin:0 0 24px 0;font-size:14px;color:#cccccc;line-height:1.8;' . $align . '">' . $text . '</p>';
}

/**
 * Horizontal divider.
 */
function emailDivider(): string {
    return '<div style="height:1px;background-color:#1f1f1f;margin:0 0 24px 0;"></div>';
}

/**
 * Info card with colored left border.
 * $rows = ['Label' => 'Value', ...]
 */
function emailInfoCard(string $title, array $rows, string $borderColor = '#d4af37'): string {
    $html = '<div style="background-color:#0d1117;border-left:3px solid ' . $borderColor . ';padding:18px 22px;margin:0 0 20px 0;border-radius:0 4px 4px 0;">';
    $html .= '<p style="margin:0 0 10px 0;font-size:12px;color:' . $borderColor . ';font-weight:700;text-transform:uppercase;letter-spacing:1px;">' . htmlspecialchars($title) . '</p>';
    foreach ($rows as $label => $value) {
        $html .= '<p style="margin:0 0 6px 0;font-size:13px;color:#999999;"><strong style="color:#cccccc;">' . htmlspecialchars($label) . ':</strong> ' . $value . '</p>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Notice / callout block with colored left border.
 */
function emailNotice(string $title, string $message, string $borderColor = '#2563eb'): string {
    return '<div style="background-color:#0d1117;border-left:3px solid ' . $borderColor . ';padding:16px 22px;margin:0 0 20px 0;border-radius:0 4px 4px 0;">
        <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
            <strong style="color:' . $borderColor . ';display:block;margin-bottom:4px;font-size:11px;text-transform:uppercase;letter-spacing:1px;">' . htmlspecialchars($title) . '</strong>
            ' . $message . '
        </p>
    </div>';
}

/**
 * Centered status badge (e.g. verification code, status).
 */
function emailStatusBadge(string $label, string $value, string $borderColor = '#2563eb', string $subtext = ''): string {
    $sub = $subtext ? '<p style="margin:10px 0 0 0;font-size:12px;color:#777777;">' . htmlspecialchars($subtext) . '</p>' : '';
    return '<div style="text-align:center;margin:0 0 28px 0;">
        <div style="display:inline-block;background-color:#0d1117;border:1px solid ' . $borderColor . ';border-radius:4px;padding:22px 36px;">
            <p style="margin:0 0 8px 0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:' . $borderColor . ';">' . htmlspecialchars($label) . '</p>
            <div style="font-size:36px;font-weight:700;letter-spacing:10px;color:#ffffff;font-family:\'SF Mono\',\'Courier New\',monospace;">' . $value . '</div>
            ' . $sub . '
        </div>
    </div>';
}

/**
 * Closing paragraph (usually centered, muted).
 */
function emailClosing(string $text): string {
    return '<p style="margin:0;font-size:13px;color:#777777;line-height:1.6;text-align:center;">' . $text . '</p>';
}

/**
 * Signature block.
 */
function emailSignature(string $closing = 'Best regards'): string {
    return '<p style="margin:0;font-size:14px;color:#999999;line-height:1.7;">
        ' . htmlspecialchars($closing) . ',<br>
        <strong style="color:#d4af37;">The HomeEstate Realty Team</strong>
    </p>';
}
