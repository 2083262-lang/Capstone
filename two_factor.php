<?php
session_start();

// ── Security headers ──
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Guard: must have a pending login
if (!isset($_SESSION['pending_login']) || !is_array($_SESSION['pending_login'])) {
    header('Location: login.php');
    exit();
}

// ── Pending-login expiration check (10 minutes max) ──
$pendingAge = time() - ($_SESSION['pending_login']['created_at'] ?? 0);
if ($pendingAge > 600) {
    unset($_SESSION['pending_login'], $_SESSION['twofa_csrf_token'], $_SESSION['twofa_init_sent']);
    header('Location: login.php');
    exit();
}

// ── CSRF token: ensure one exists (generated in login.php) ──
if (empty($_SESSION['twofa_csrf_token'])) {
    $_SESSION['twofa_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['twofa_csrf_token'];

$pending  = $_SESSION['pending_login'];
$autoSend = false;
// Auto-send exactly once per login flow, keyed by account_id
if (!empty($pending['account_id'])) {
    if (!isset($_SESSION['twofa_init_sent']) || $_SESSION['twofa_init_sent'] !== $pending['account_id']) {
        $autoSend = true;
        $_SESSION['twofa_init_sent'] = $pending['account_id'];
    }
}
$maskedEmail = isset($pending['email'])
    ? preg_replace('/(^.).*(@.*$)/', '$1***$2', $pending['email'])
    : 'your email';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Two-Factor Authentication – HomeEstate Realty</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ═══════════════════════════════════════════════
           Design tokens — mirrors login.php exactly
        ═══════════════════════════════════════════════ */
        :root {
            --twofa-gold:        #d4af37;
            --twofa-gold-light:  #f4d03f;
            --twofa-gold-dark:   #b8941f;
            --twofa-blue:        #2563eb;
            --twofa-blue-light:  #3b82f6;
            --twofa-black:       #0a0a0a;
            --twofa-black-light: #111111;
            --twofa-black-mid:   #1a1a1a;
            --twofa-white:       #ffffff;
            --twofa-gray-300:    #b8bec4;
            --twofa-gray-400:    #9ca4ab;
            --twofa-gray-500:    #7a8a99;
            --twofa-gray-600:    #5d6d7d;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html {
            scrollbar-width: thin;
            scrollbar-color: var(--twofa-gold-dark) var(--twofa-black);
        }
        ::-webkit-scrollbar          { width: 6px; }
        ::-webkit-scrollbar-track    { background: var(--twofa-black); }
        ::-webkit-scrollbar-thumb    {
            background: linear-gradient(180deg, var(--twofa-gold-dark), var(--twofa-gold));
            border-radius: 10px;
        }

        body, html {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--twofa-black);
            color: var(--twofa-white);
            line-height: 1.6;
        }

        /* ═══════════════════════════════════════════════
           Full-page wrapper
           KEY FIX: flex-direction: column so the footer
           badge stacks below the card instead of beside it
        ═══════════════════════════════════════════════ */
        .twofa-page {
            display: flex;
            flex-direction: column;          /* ← stacks card + footer vertically */
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            width: 100%;
            padding: 2rem;
            position: relative;
            overflow-y: auto;
            background:
                radial-gradient(circle at 20% 30%, rgba(37,99,235,.07) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(212,175,55,.06) 0%, transparent 50%),
                linear-gradient(rgba(10,10,10,.78), rgba(10,10,10,.85)),
                url('images/hero-bg2.jpg') center/cover no-repeat fixed;
        }
        /* Subtle dot-grid overlay */
        .twofa-page::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(circle, rgba(212,175,55,.08) 1px, transparent 1px),
                radial-gradient(circle, rgba(37,99,235,.05)  1px, transparent 1px);
            background-size: 60px 60px, 90px 90px;
            background-position: 0 0, 30px 30px;
            pointer-events: none;
            z-index: 0;
        }

        /* ═══════════════════════════════════════════════
           Glassmorphism card — wider at 560 px
        ═══════════════════════════════════════════════ */
        .twofa-card {
            width: 100%;
            max-width: 680px;
            position: relative;
            z-index: 1;
            background: rgba(17,17,17,.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(212,175,55,.06);
            border-radius: 20px;
            box-shadow:
                0 8px 40px rgba(0,0,0,.5),
                inset 0 1px 0 rgba(255,255,255,.04);
            overflow: hidden;
            animation: twofa-fadeInUp .5s ease-out both;
        }
        /* Gold accent stripe */
        .twofa-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--twofa-gold), transparent);
            border-radius: 20px 20px 0 0;
            pointer-events: none;
        }

        .twofa-card-body { padding: 48px 64px; }

        /* ── Header icon ── */
        .twofa-icon {
            width: 72px; height: 72px;
            background: linear-gradient(135deg, var(--twofa-gold-dark) 0%, var(--twofa-gold) 50%, var(--twofa-gold-dark) 100%);
            border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(212,175,55,.3), 0 0 0 1px rgba(212,175,55,.2);
        }
        .twofa-icon i { font-size: 2rem; color: var(--twofa-white); }

        .twofa-title {
            font-weight: 700; font-size: 1.75rem;
            color: var(--twofa-white);
            text-shadow: 0 2px 10px rgba(0,0,0,.3);
            margin-bottom: 6px;
        }
        .twofa-subtitle { color: var(--twofa-gray-400); font-size: .9rem; line-height: 1.7; }
        .twofa-subtitle strong { color: var(--twofa-gold); }

        /* ── Divider (login.php style) ── */
        .twofa-divider {
            display: flex; align-items: center; gap: 12px;
            margin: 24px 0 20px;
        }
        .twofa-divider::before,
        .twofa-divider::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,.3), transparent);
        }
        .twofa-divider span {
            font-size: .7rem; letter-spacing: 3px; text-transform: uppercase;
            color: var(--twofa-gold); font-weight: 600; white-space: nowrap;
        }

        /* ── Form elements ── */
        .twofa-label { font-weight: 500; color: var(--twofa-gray-300); margin-bottom: 8px; display: block; }

        .twofa-otp-input {
            display: block; width: 100%;
            letter-spacing: .8rem;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 600;
            height: 64px;
            border: 1px solid rgba(37,99,235,.3);
            border-radius: 2px;
            background: rgba(10,10,10,.6);
            color: var(--twofa-white);
            transition: border-color .3s, box-shadow .3s, background .3s;
            padding: 0 12px;
        }
        .twofa-otp-input:focus {
            border-color: var(--twofa-blue);
            background: rgba(10,10,10,.8);
            box-shadow: 0 0 0 .25rem rgba(37,99,235,.15), 0 4px 16px rgba(37,99,235,.2);
            color: var(--twofa-white);
            outline: none;
        }
        .twofa-otp-input::placeholder { color: var(--twofa-gray-600); letter-spacing: .5rem; }
        /* Invalid state */
        .twofa-otp-input.is-invalid { border-color: rgba(220,53,69,.6) !important; }
        .twofa-invalid-msg { font-size: .82rem; color: #ff6b6b; margin-top: 6px; display: none; }
        .twofa-invalid-msg.visible { display: block; }

        /* ── Submit button — same gradient/shimmer as login.php ── */
        .twofa-btn {
            display: block; width: 100%;
            background: linear-gradient(135deg, var(--twofa-gold-dark) 0%, var(--twofa-gold) 50%, var(--twofa-gold-dark) 100%);
            color: var(--twofa-black); border: none;
            padding: 14px; border-radius: 2px;
            font-weight: 700; font-size: 16px;
            cursor: pointer;
            transition: transform .3s ease, box-shadow .3s ease;
            box-shadow: 0 4px 16px rgba(212,175,55,.25), 0 0 0 1px rgba(212,175,55,.2);
            position: relative; overflow: hidden;
        }
        .twofa-btn::before {
            content: '';
            position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.3), transparent);
            transition: left .5s ease;
        }
        .twofa-btn:hover { transform: translateY(-2px); color: var(--twofa-black);
            box-shadow: 0 8px 24px rgba(212,175,55,.4), 0 0 0 1px rgba(212,175,55,.4), 0 0 30px rgba(212,175,55,.2); }
        .twofa-btn:hover::before { left: 100%; }
        .twofa-btn:disabled { opacity: .7; transform: none !important; cursor: not-allowed; }

        /* ── Error alert ── */
        .twofa-alert {
            background: rgba(220,53,69,.15);
            border: 1px solid rgba(220,53,69,.3);
            border-radius: 2px;
            color: #ff6b6b;
            padding: 12px 16px;
            font-size: .88rem;
            margin-bottom: 16px;
        }

        /* Code-expiry counter */
        .twofa-timer { color: var(--twofa-gray-500); font-size: .82rem; margin-top: 6px; }

        /* ── Resend row ── */
        .twofa-resend-row { color: var(--twofa-gray-500); font-size: .88rem; }
        .twofa-resend-btn {
            color: var(--twofa-gold); font-weight: 600; font-size: .88rem;
            background: transparent; border: none;
            padding: 4px 8px; border-radius: 2px;
            transition: background .2s, color .2s; cursor: pointer;
        }
        .twofa-resend-btn:hover  { background: rgba(212,175,55,.1); color: var(--twofa-gold-light); }
        .twofa-resend-btn:disabled { opacity: .45; pointer-events: none; }

        /* ── Back to login link ── */
        .twofa-back-link {
            text-align: center;
            margin-top: 20px;
            font-size: .85rem;
            color: var(--twofa-gray-500);
        }
        .twofa-back-link a {
            color: var(--twofa-gold);
            font-weight: 600;
            text-decoration: none;
            transition: color .2s;
        }
        .twofa-back-link a:hover { color: var(--twofa-gold-light); }

        /* ── Footer badge (now properly below the card) ── */
        .twofa-footer {
            position: relative; z-index: 1;
            text-align: center;
            margin-top: 20px;
        }
        .twofa-footer-badge {
            background: rgba(37,99,235,.08);
            border: 1px solid rgba(37,99,235,.2);
            backdrop-filter: blur(10px);
            padding: 10px 22px; border-radius: 2px;
            display: inline-block; font-size: .82rem;
            color: var(--twofa-gray-400);
            box-shadow: 0 4px 12px rgba(0,0,0,.2);
        }
        .twofa-footer-badge i { color: var(--twofa-gold); }

        /* ═══════════════════════════════════════════════
           Sending email overlay
        ═══════════════════════════════════════════════ */
        .twofa-send-overlay {
            position: fixed; inset: 0;
            background: rgba(10,10,10,.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex; align-items: center; justify-content: center;
            z-index: 1050;
        }
        .twofa-send-box {
            background: linear-gradient(135deg, rgba(26,26,26,.95) 0%, rgba(10,10,10,.98) 100%);
            border: 1px solid rgba(37,99,235,.2);
            border-radius: 4px;
            padding: 48px 40px; width: 400px; max-width: 90vw;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.5);
            position: relative;
        }
        .twofa-send-box::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--twofa-gold), transparent);
        }
        .twofa-plane { width: 72px; height: 72px; margin: 0 auto 8px; position: relative; }
        .twofa-plane::before {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(135deg, var(--twofa-gold-dark) 0%, var(--twofa-gold) 50%, var(--twofa-gold-light) 100%);
            clip-path: polygon(50% 0, 0 100%, 100% 100%);
            border-radius: 4px;
            box-shadow: 0 8px 24px rgba(212,175,55,.4);
            animation: twofa-floatPlane 2s ease-in-out infinite;
        }
        .twofa-trail {
            width: 6px; height: 56px; margin: 0 auto;
            background: linear-gradient(to bottom, rgba(212,175,55,.8), transparent);
            border-radius: 4px;
        }
        .twofa-send-title  { font-size: 1.15rem; font-weight: 600; color: var(--twofa-white); margin-bottom: 4px; }
        .twofa-send-status { font-size: .9rem; color: var(--twofa-gray-400); }

        /* ═══════════════════════════════════════════════
           Success overlay
        ═══════════════════════════════════════════════ */
        .twofa-success-overlay {
            position: fixed; inset: 0;
            background: rgba(10,10,10,.92);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            display: flex; align-items: center; justify-content: center;
            z-index: 2000;
        }
        .twofa-success-box {
            text-align: center;
        }
        .twofa-success-circle {
            width: 100px; height: 100px; border-radius: 50%;
            background: linear-gradient(135deg, #166534 0%, #16a34a 50%, #22c55e 100%);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px;
        }
        .twofa-success-circle i { font-size: 2.8rem; color: var(--twofa-white); }
        .twofa-success-title {
            font-size: 1.9rem; font-weight: 700; color: var(--twofa-white);
            text-shadow: 0 2px 16px rgba(34,197,94,.25);
            margin-bottom: 8px;
        }
        .twofa-success-subtitle { font-size: .95rem; color: var(--twofa-gray-400); margin-bottom: 16px; }
        .twofa-success-gold-line {
            width: 60px; height: 3px; margin: 0 auto 20px;
            background: linear-gradient(90deg, transparent, var(--twofa-gold), transparent);
            border-radius: 2px;
        }
        .twofa-progress-bar-wrap {
            width: 180px; height: 3px; margin: 0 auto;
            background: rgba(255,255,255,.1);
            border-radius: 2px; overflow: hidden;
        }
        .twofa-progress-bar-fill {
            height: 100%; width: 0;
            background: linear-gradient(90deg, var(--twofa-gold-dark), var(--twofa-gold-light));
            border-radius: 2px;
        }
        .twofa-redirect-label { font-size: .78rem; color: var(--twofa-gray-500); margin-top: 8px; }

        /* Page exit: card fades/scales out before nav */
        .twofa-page-exit .twofa-card {
            animation: twofa-exitCard .38s cubic-bezier(.4,0,1,1) forwards !important;
        }

        /* ═══════════════════════════════════════════════
           Keyframes — all prefixed twofa-
        ═══════════════════════════════════════════════ */
        @keyframes twofa-fadeInUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes twofa-exitCard {
            to { opacity: 0; transform: scale(.95) translateY(-16px); }
        }
        @keyframes twofa-floatPlane {
            0%,100% { transform: translateY(0); }
            50%     { transform: translateY(-8px); }
        }
        @keyframes twofa-successFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        @keyframes twofa-successDropIn {
            from { opacity: 0; transform: scale(.6); }
            to   { opacity: 1; transform: scale(1); }
        }
        @keyframes twofa-successPulse {
            0%   { box-shadow: 0 0 0 0   rgba(34,197,94,.55); }
            70%  { box-shadow: 0 0 0 30px rgba(34,197,94,0); }
            100% { box-shadow: 0 0 0 0   rgba(34,197,94,0); }
        }
        @keyframes twofa-progressFill {
            to { width: 100%; }
        }
        @keyframes twofa-shake {
            0%,100% { transform: translateX(0); }
            20%     { transform: translateX(-7px); }
            40%     { transform: translateX(7px); }
            60%     { transform: translateX(-4px); }
            80%     { transform: translateX(4px); }
        }

        /* ── Responsive ── */
        @media (max-width: 720px) {
            .twofa-card-body { padding: 36px 28px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .twofa-card, .twofa-success-box, .twofa-success-overlay,
            .twofa-success-circle, .twofa-progress-bar-fill,
            .twofa-plane::before { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>

<div class="twofa-page" id="twofaPage">

    <!-- ── Card ── -->
    <div class="twofa-card" id="twofaCard">
        <div class="twofa-card-body">

            <!-- Header -->
            <div class="text-center mb-2">
                <div class="twofa-icon">
                    <i class="fas fa-shield-halved"></i>
                </div>
                <h1 class="twofa-title">Two-Factor Verification</h1>
                <p class="twofa-subtitle">
                    A 6-digit code was sent to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong><br>
                    Enter it below to continue.
                </p>
            </div>

            <div class="twofa-divider"><span>Enter Verification Code</span></div>

            <!-- Server-side error (non-JS fallback) -->
            <?php if (!empty($_SESSION['twofa_error'])): ?>
                <div class="twofa-alert" role="alert">
                    <i class="fas fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($_SESSION['twofa_error']); unset($_SESSION['twofa_error']); ?>
                </div>
            <?php endif; ?>

            <!-- JS-injected error -->
            <div id="twofaError" class="twofa-alert" role="alert" style="display:none;"></div>

            <!-- Verification form -->
            <form id="twofaForm" action="verify_2fa.php" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="mb-3">
                    <label for="twofaCode" class="twofa-label">Verification code</label>
                    <input
                        type="text"
                        class="twofa-otp-input"
                        id="twofaCode"
                        name="code"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder="000000"
                        required
                    />
                    <div class="twofa-timer" id="twofaTimer" aria-live="polite">Waiting for code…</div>
                    <div class="twofa-invalid-msg" id="twofaInvalidMsg">
                        <i class="fas fa-triangle-exclamation me-1"></i>Please enter the full 6-digit code.
                    </div>
                </div>

                <button type="submit" class="twofa-btn" id="twofaSubmitBtn">
                    <i class="fas fa-shield-halved me-2"></i>Verify and Continue
                </button>
            </form>

            <!-- Resend row -->
            <div class="d-flex align-items-center justify-content-between mt-3">
                <span id="twofaResendText" class="twofa-resend-row">Didn't receive the email?</span>
                <button id="twofaResendBtn" class="twofa-resend-btn" type="button" onclick="twofaSendCode()">
                    <i class="fas fa-rotate-right me-1"></i>Resend code
                </button>
            </div>

            <!-- Back to login -->
            <div class="twofa-back-link">
                <a href="login.php"><i class="fas fa-arrow-left me-1"></i>Back to login</a>
            </div>

        </div><!-- /.twofa-card-body -->
    </div><!-- /.twofa-card -->

    <!-- Footer (now stacks below the card due to flex-direction: column) -->
    <div class="twofa-footer">
        <span class="twofa-footer-badge">
            <i class="fas fa-shield-halved me-2"></i>Secured by email verification · Code expires in 5 minutes
        </span>
    </div>

</div><!-- /.twofa-page -->

<!-- ── Sending overlay ── -->
<div class="twofa-send-overlay" id="twofaSendOverlay" style="display:none;">
    <div class="twofa-send-box">
        <div class="twofa-plane"></div>
        <div class="twofa-trail mb-3"></div>
        <div class="twofa-send-title">Sending verification code</div>
        <div class="twofa-send-status" id="twofaSendStatus">Please wait…</div>
    </div>
</div>

<!-- ── Success overlay ── -->
<div class="twofa-success-overlay" id="twofaSuccessOverlay" style="display:none;">
    <div class="twofa-success-box" id="twofaSuccessBox">
        <div class="twofa-success-circle" id="twofaSuccessCircle">
            <i class="fas fa-check"></i>
        </div>
        <h2 class="twofa-success-title">Verified!</h2>
        <p class="twofa-success-subtitle">Identity confirmed. Redirecting you now…</p>
        <div class="twofa-success-gold-line"></div>
        <div class="twofa-progress-bar-wrap">
            <div class="twofa-progress-bar-fill" id="twofaProgressFill"></div>
        </div>
        <p class="twofa-redirect-label">Redirecting…</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    /* ── Security: CSRF token from server ── */
    const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

    /* ── Constants ── */
    const CODE_TTL_SECONDS   = 300;  // 5 minutes (matches server)
    const RESEND_COOLDOWN    = 60;   // 60 seconds between resends

    /* ── State ── */
    let codeTimer    = null;
    let cooldownTimer = null;
    let isSubmitting  = false;

    /* ── Element refs ── */
    const page       = document.getElementById('twofaPage');
    const form       = document.getElementById('twofaForm');
    const inp        = document.getElementById('twofaCode');
    const submitBtn  = document.getElementById('twofaSubmitBtn');
    const errorBox   = document.getElementById('twofaError');
    const timerEl    = document.getElementById('twofaTimer');
    const resendBtn  = document.getElementById('twofaResendBtn');
    const resendTxt  = document.getElementById('twofaResendText');
    const invalidMsg = document.getElementById('twofaInvalidMsg');

    /* ═══════════════════════════════════════════════
       Code expiry countdown
    ═══════════════════════════════════════════════ */
    function startCodeTimer(secs) {
        if (!timerEl) return;
        let rem = secs;
        clearInterval(codeTimer);
        const formatTime = (s) => {
            if (s >= 60) {
                const m = Math.floor(s / 60);
                const sec = s % 60;
                return `${m}:${String(sec).padStart(2, '0')}`;
            }
            return `${s}s`;
        };
        timerEl.textContent = `Code expires in ${formatTime(rem)}`;
        codeTimer = setInterval(() => {
            rem -= 1;
            if (rem <= 0) {
                clearInterval(codeTimer);
                timerEl.textContent = 'Code has expired. Click Resend to get a new one.';
            } else {
                timerEl.textContent = `Code expires in ${formatTime(rem)}`;
            }
        }, 1000);
    }

    /* ═══════════════════════════════════════════════
       Resend cooldown
    ═══════════════════════════════════════════════ */
    function startResendCooldown(secs) {
        let rem = secs;
        resendBtn.disabled = true;
        resendTxt.textContent = `You can resend in ${rem}s`;
        clearInterval(cooldownTimer);
        cooldownTimer = setInterval(() => {
            rem -= 1;
            if (rem <= 0) {
                clearInterval(cooldownTimer);
                resendTxt.textContent = "Didn't receive the email?";
                resendBtn.disabled = false;
            } else {
                resendTxt.textContent = `You can resend in ${rem}s`;
            }
        }, 1000);
    }

    /* ═══════════════════════════════════════════════
       Show / hide error
    ═══════════════════════════════════════════════ */
    function showError(msg) {
        errorBox.innerHTML = '<i class="fas fa-circle-exclamation me-2"></i>' + msg;
        errorBox.style.display = 'block';
    }
    function hideError() {
        errorBox.style.display = 'none';
    }

    /* ═══════════════════════════════════════════════
       Send OTP via send_2fa.php
    ═══════════════════════════════════════════════ */
    async function twofaSendCode(initial = false) {
        const overlay  = document.getElementById('twofaSendOverlay');
        const statusEl = document.getElementById('twofaSendStatus');
        overlay.style.display = 'flex';
        statusEl.textContent  = 'Sending verification code…';
        hideError();

        try {
            const res  = await fetch('send_2fa.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                }
            });
            const data = await res.json();

            // Handle session expiration
            if (data.expired || res.status === 401) {
                statusEl.textContent = 'Session expired.';
                setTimeout(() => { window.location.href = 'login.php'; }, 1200);
                return;
            }

            if (data.success) {
                statusEl.textContent = 'Code sent! Check your inbox.';
                setTimeout(() => { overlay.style.display = 'none'; }, 600);
                // Use server-reported TTL, fallback to constant
                startCodeTimer(data.ttl || CODE_TTL_SECONDS);
                startResendCooldown(RESEND_COOLDOWN);
            } else if (data.retryAfter) {
                startResendCooldown(data.retryAfter);
                statusEl.textContent = `Please wait ${data.retryAfter}s before requesting a new code.`;
                startCodeTimer(data.retryAfter);
                setTimeout(() => { overlay.style.display = 'none'; }, 900);
            } else {
                statusEl.textContent = 'Failed to send code.';
                setTimeout(() => { overlay.style.display = 'none'; }, 900);
                showError(data.message || 'We could not send the email. Please check your spam folder or try again.');
            }
        } catch (e) {
            statusEl.textContent = 'Network error.';
            setTimeout(() => { overlay.style.display = 'none'; }, 900);
            showError('Network error. Please check your connection and try again.');
        }
    }

    /* ═══════════════════════════════════════════════
       Success overlay — JS-driven animations so we
       control exact timing and can re-trigger reliably
    ═══════════════════════════════════════════════ */
    function showSuccessAndRedirect(url) {
        const overlay  = document.getElementById('twofaSuccessOverlay');
        const box      = document.getElementById('twofaSuccessBox');
        const circle   = document.getElementById('twofaSuccessCircle');
        const fill     = document.getElementById('twofaProgressFill');

        // Reset all animated elements before displaying
        overlay.style.opacity = '0';
        box.style.opacity     = '0';
        box.style.transform   = 'scale(0.6)';
        circle.style.boxShadow = '0 0 0 0 rgba(34,197,94,.55)';
        fill.style.transition  = 'none';
        fill.style.width       = '0';

        overlay.style.display = 'flex';

        // Tick 1: fade in backdrop (50 ms)
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                overlay.style.transition = 'opacity .35s ease';
                overlay.style.opacity    = '1';

                // Tick 2: pop in the box (100 ms after backdrop starts)
                setTimeout(() => {
                    box.style.transition  = 'opacity .45s cubic-bezier(.34,1.56,.64,1), transform .45s cubic-bezier(.34,1.56,.64,1)';
                    box.style.opacity     = '1';
                    box.style.transform   = 'scale(1)';

                    // Tick 3: pulse ring on circle (200 ms later)
                    setTimeout(() => {
                        circle.style.animation = 'twofa-successPulse 1.2s ease-out forwards';
                    }, 200);

                    // Tick 4: start progress bar after 0.5 s
                    setTimeout(() => {
                        fill.style.transition = 'width 1.7s linear';
                        fill.style.width      = '100%';
                    }, 500);
                }, 100);
            });
        });

        // Navigate once progress bar finishes (0.5 s delay + 1.7 s fill + small buffer)
        setTimeout(() => {
            page.classList.add('twofa-page-exit');
            setTimeout(() => { window.location.href = url; }, 420);
        }, 2550);
    }

    /* ═══════════════════════════════════════════════
       Shake the input on wrong code
    ═══════════════════════════════════════════════ */
    function shakeInput() {
        inp.style.animation = 'none';
        void inp.offsetWidth; // force reflow
        inp.style.animation = 'twofa-shake .4s ease';
        inp.addEventListener('animationend', () => { inp.style.animation = ''; }, { once: true });
    }

    /* ═══════════════════════════════════════════════
       AJAX form submit
    ═══════════════════════════════════════════════ */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (isSubmitting) return;

        const code = (inp.value || '').replace(/\D/g, '');

        // Client-side validation
        if (code.length !== 6) {
            inp.classList.add('is-invalid');
            invalidMsg.classList.add('visible');
            inp.focus();
            return;
        }
        inp.classList.remove('is-invalid');
        invalidMsg.classList.remove('visible');

        isSubmitting = true;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verifying…';
        hideError();

        try {
            const res  = await fetch('verify_2fa.php', {
                method: 'POST',
                headers: {
                    'Content-Type':       'application/x-www-form-urlencoded',
                    'X-Requested-With':   'XMLHttpRequest',
                    'X-CSRF-Token':       CSRF_TOKEN
                },
                body: new URLSearchParams({ code }).toString()
            });

            // If server returned a non-JSON page (e.g. PHP fatal), handle gracefully
            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('Unexpected server response.');
            }

            const data = await res.json();

            if (data.success && data.redirect) {
                // Lock the form so double-tap can't re-submit
                submitBtn.innerHTML = '<i class="fas fa-check me-2"></i>Verified!';
                showSuccessAndRedirect(data.redirect);
                return; // keep isSubmitting = true so form stays locked
            }

            // Handle specific error cases
            const msg = data.error || 'Verification failed. Please try again.';

            // Session expired → redirect to login after brief pause
            if (res.status === 401) {
                showError('Session expired. Redirecting to login…');
                setTimeout(() => { window.location.href = 'login.php'; }, 2200);
                return;
            }

            showError(msg);
            shakeInput();
            inp.value = '';
            inp.focus();
        } catch (err) {
            showError('Network error. Please check your connection and try again.');
            shakeInput();
        } finally {
            if (isSubmitting && !document.getElementById('twofaSuccessOverlay').style.display.includes('flex')) {
                // Only re-enable if success overlay not showing
            }
            isSubmitting = false;
            // Re-enable button only if not navigating
            if (!document.getElementById('twofaSuccessOverlay').style.display.includes('flex')) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-shield-halved me-2"></i>Verify and Continue';
            }
        }
    });

    /* ═══════════════════════════════════════════════
       Digit-only input + auto-submit at 6 digits
    ═══════════════════════════════════════════════ */
    inp.addEventListener('input', () => {
        inp.value = (inp.value || '').replace(/\D/g, '').slice(0, 6);

        // Clear validation state while typing
        if (inp.classList.contains('is-invalid') && inp.value.length > 0) {
            inp.classList.remove('is-invalid');
            invalidMsg.classList.remove('visible');
        }

        // Auto-submit once 6 digits are entered
        if (inp.value.length === 6) {
            setTimeout(() => {
                form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }, 200);
        }
    });

    inp.addEventListener('keydown', (e) => {
        // Allow: backspace, delete, tab, escape, enter, arrows, ctrl+a/v/c/x
        if (
            [8, 9, 13, 27, 35, 36, 37, 38, 39, 40, 46].includes(e.keyCode) ||
            (e.ctrlKey || e.metaKey)
        ) return;
        // Block non-digit keys
        if (!/^\d$/.test(e.key)) e.preventDefault();
    });

    /* ═══════════════════════════════════════════════
       Init
    ═══════════════════════════════════════════════ */
    inp.focus();
    const shouldAutoSend = <?php echo $autoSend ? 'true' : 'false'; ?>;
    if (shouldAutoSend) twofaSendCode(true);

    // Expose for inline onclick attribute
    window.twofaSendCode = twofaSendCode;

})();
</script>
</body>
</html>
