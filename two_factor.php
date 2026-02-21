<?php
session_start();

// Ensure there is a pending login awaiting 2FA
if (!isset($_SESSION['pending_login']) || !is_array($_SESSION['pending_login'])) {
    header('Location: login.php');
    exit();
}

$pending = $_SESSION['pending_login'];
$autoSend = false;
// Auto-send should happen only once per login flow. Use a session flag keyed by account_id.
if (!empty($pending['account_id'])) {
    if (!isset($_SESSION['twofa_init_sent']) || $_SESSION['twofa_init_sent'] !== $pending['account_id']) {
        $autoSend = true;
        $_SESSION['twofa_init_sent'] = $pending['account_id'];
    }
}
$maskedEmail = isset($pending['email']) ? preg_replace('/(^.).*(@.*$)/', '$1***$2', $pending['email']) : 'your email';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Two-Factor Authentication</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            /* Primary Brand Colors */
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            
            /* Neutral Palette */
            --black: #0a0a0a;
            --black-light: #111111;
            --black-lighter: #1a1a1a;
            --black-border: #1f1f1f;
            --white: #ffffff;
            
            /* Semantic Grays */
            --gray-50: #f8f9fa;
            --gray-100: #e8e9eb;
            --gray-200: #d1d4d7;
            --gray-300: #b8bec4;
            --gray-400: #9ca4ab;
            --gray-500: #7a8a99;
            --gray-600: #5d6d7d;
            --gray-700: #3f4b56;
            --gray-800: #2a3138;
            --gray-900: #1a1f24;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px 0;
            color: var(--white);
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(212, 175, 55, 0.05) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .container-narrow { 
            max-width: 520px; 
            position: relative;
            z-index: 1;
        }

        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.3),
                        0 0 0 1px rgba(212, 175, 55, 0.2);
        }

        .header-icon i { 
            font-size: 2.5rem; 
            color: var(--white); 
        }

        .page-title { 
            font-weight: 700; 
            font-size: 1.75rem; 
            color: var(--white); 
            margin-bottom: 8px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .page-subtitle { 
            color: var(--gray-400); 
            font-size: 0.95rem; 
            line-height: 1.6; 
        }

        .page-subtitle strong {
            color: var(--gold);
        }

        .card { 
            border: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4); 
            border-radius: 4px;
            backdrop-filter: blur(10px);
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.9) 0%, rgba(10, 10, 10, 0.95) 100%);
            position: relative;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            border-radius: 4px 4px 0 0;
        }

        .otp-input { 
            letter-spacing: .8rem; 
            text-align: center; 
            font-size: 1.5rem; 
            font-weight: 600;
            border: 1px solid rgba(37, 99, 235, 0.3);
            border-radius: 2px;
            padding: 18px;
            transition: all 0.3s ease;
            background: rgba(10, 10, 10, 0.6);
            color: var(--white);
        }

        .otp-input:focus {
            border-color: var(--blue);
            background: rgba(10, 10, 10, 0.8);
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.15),
                        0 4px 16px rgba(37, 99, 235, 0.2);
            color: var(--white);
        }

        .otp-input::placeholder {
            color: var(--gray-600);
        }

        .btn-primary { 
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black); 
            border: none;
            padding: 14px;
            font-weight: 700;
            border-radius: 2px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25), 
                        0 0 0 1px rgba(212, 175, 55, 0.2);
            position: relative;
            overflow: hidden;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-primary:hover { 
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4), 
                        0 0 0 1px rgba(212, 175, 55, 0.4),
                        0 0 30px rgba(212, 175, 55, 0.2);
            color: var(--black);
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        /* Sending overlay animation */
        .sending-overlay { 
            position: fixed; 
            inset: 0; 
            background: rgba(10, 10, 10, 0.9);
            backdrop-filter: blur(8px);
            display: flex; 
            align-items: center; 
            justify-content: center; 
            z-index: 1050;
        }

        .send-box { 
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(10, 10, 10, 0.98) 100%);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 4px; 
            padding: 48px 40px; 
            width: 400px; 
            text-align: center; 
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            position: relative;
        }

        .send-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
        }

        .plane { 
            width: 80px; 
            height: 80px; 
            margin: 0 auto 20px; 
            position: relative; 
        }

        .plane:before { 
            content: ""; 
            position: absolute; 
            inset: 0; 
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-light) 100%);
            clip-path: polygon(50% 0, 0 100%, 100% 100%); 
            border-radius: 4px;
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4);
            animation: float 2s ease-in-out infinite;
        }

        .trail { 
            width: 8px; 
            height: 70px; 
            margin: 0 auto; 
            background: linear-gradient(to bottom, rgba(212, 175, 55, 0.8), rgba(212, 175, 55, 0)); 
            border-radius: 4px; 
        }

        @keyframes float { 
            0%,100% { transform: translateY(0);} 
            50% { transform: translateY(-8px);} 
        }

        .send-box .fw-semibold {
            font-size: 1.25rem;
            color: var(--white);
        }

        .send-box .small-muted {
            font-size: 0.95rem;
            color: var(--gray-400);
        }

        .small-muted { 
            color: var(--gray-500); 
            font-size: .9rem; 
        }

        .resend { 
            color: var(--gold); 
            font-weight: 600; 
            text-decoration: none;
            transition: all 0.2s ease;
            padding: 4px 8px;
            border-radius: 2px;
            background: transparent;
            border: none;
        }

        .resend:hover {
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold-light);
        }

        .resend[disabled] { 
            pointer-events: none; 
            opacity: .5; 
        }

        .alert {
            border: none;
            border-radius: 2px;
            padding: 16px 20px;
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #ff6b6b;
            border-left: 4px solid #fc8181;
        }

        .form-label {
            font-weight: 600;
            color: var(--gray-300);
            margin-bottom: 10px;
            font-size: 0.95rem;
        }

        .invalid-feedback {
            font-size: 0.875rem;
            margin-top: 8px;
            color: #ff6b6b;
        }

        .footer-text {
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(37, 99, 235, 0.2);
            backdrop-filter: blur(10px);
            padding: 12px 24px;
            border-radius: 2px;
            display: inline-block;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            color: var(--gray-400);
        }

        .footer-text i {
            color: var(--gold);
        }
    </style>
    <script>
        let resendCooldown = 60; // seconds
        let coolDownTimer = null;
        let codeTtlSeconds = 60; // code lifetime
        let codeTimer = null;

        function startCodeTimer(seconds) {
            try {
                const el = document.getElementById('codeTimer');
                if (!el) return;
                let remaining = seconds;
                el.textContent = `Code expires in ${remaining}s`;
                clearInterval(codeTimer);
                codeTimer = setInterval(() => {
                    remaining -= 1;
                    if (remaining <= 0) {
                        clearInterval(codeTimer);
                        el.textContent = 'Code has expired. Click Resend to request a new code.';
                    } else {
                        el.textContent = `Code expires in ${remaining}s`;
                    }
                }, 1000);
            } catch(e) {}
        }

        async function sendCode(initial = false) {
            const overlay = document.getElementById('sendingOverlay');
            const statusEl = document.getElementById('sendStatus');
            overlay.style.display = 'flex';
            statusEl.textContent = 'Sending verification code...';
            try {
                const res = await fetch('send_2fa.php', { method: 'POST', headers: { 'Content-Type': 'application/json' } });
                const data = await res.json();
                if (data.success) {
                    statusEl.textContent = 'Code sent. Check your inbox.';
                    setTimeout(() => { overlay.style.display = 'none'; }, 600);
                    // Start code TTL countdown (client-side indicator only)
                    startCodeTimer(codeTtlSeconds);
                    // Start cooldown only for user-initiated sends
                    if (!initial) startResendCooldown(resendCooldown);
                } else {
                    if (data.retryAfter) {
                        startResendCooldown(data.retryAfter);
                        statusEl.textContent = `Please wait ${data.retryAfter}s before requesting a new code...`;
                        setTimeout(() => { overlay.style.display = 'none'; }, 1000);
                    } else {
                        statusEl.textContent = 'Failed to send code. Please try again.';
                        const alert = document.getElementById('sendError');
                        if (alert) {
                            alert.textContent = 'We could not send the email. Please check your spam folder or verify the account email, then try again.';
                            alert.style.display = 'block';
                        }
                        setTimeout(() => { overlay.style.display = 'none'; }, 1000);
                    }
                }
            } catch (e) {
                statusEl.textContent = 'Network error while sending code.';
                const alert = document.getElementById('sendError');
                if (alert) {
                    alert.textContent = 'Network error while sending code. Please check your connection and try again.';
                    alert.style.display = 'block';
                }
                setTimeout(() => { overlay.style.display = 'none'; }, 1000);
            }
        }

        function startResendCooldown(seconds) {
            const btn = document.getElementById('resendBtn');
            const txt = document.getElementById('resendText');
            let remaining = seconds;
            btn.setAttribute('disabled', 'disabled');
            txt.textContent = `You can resend a new code in ${remaining}s`;
            clearInterval(coolDownTimer);
            coolDownTimer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) {
                    clearInterval(coolDownTimer);
                    txt.textContent = 'Didn\'t receive the email?';
                    btn.removeAttribute('disabled');
                } else {
                    txt.textContent = `You can resend a new code in ${remaining}s`;
                }
            }, 1000);
        }

        window.addEventListener('DOMContentLoaded', () => {
            // Server-determined auto-send: only on first arrival after login
            const shouldAutoSend = <?php echo $autoSend ? 'true' : 'false'; ?>;
            if (shouldAutoSend) {
                // Auto-send triggers timer on success inside sendCode
                sendCode(true);
            }
            // Autofocus
            const input = document.getElementById('code');
            if (input) {
                input.focus();
                // Sanitize input to digits only and cap at 6
                input.addEventListener('input', () => {
                    input.value = (input.value || '').replace(/\D+/g, '').slice(0, 6);
                });
            }
        });
    </script>
    </head>
<body>
    <div class="container container-narrow py-5">
        <div class="text-center mb-4">
            <div class="header-icon">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <h1 class="page-title">Two-factor verification</h1>
            <p class="page-subtitle">We sent a 6-digit code to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong><br>Enter it below to continue</p>
        </div>

        <?php if (!empty($_SESSION['twofa_error'])): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($_SESSION['twofa_error']); unset($_SESSION['twofa_error']); ?>
            </div>
        <?php endif; ?>

        <div id="sendError" class="alert alert-danger" role="alert" style="display:none;"></div>

        <div class="card p-4">
            <form method="POST" action="verify_2fa.php" class="needs-validation" novalidate>
                <div class="mb-3">
                    <label for="code" class="form-label">Verification code</label>
                    <div class="small-muted" id="codeTimer" aria-live="polite">Code expires in 60s</div>
                    <input type="text" class="form-control otp-input" id="code" name="code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required />
                    <div class="invalid-feedback">Enter the 6-digit code from your email.</div>
                </div>
                <button class="btn btn-primary w-100 mb-2" type="submit">
                    <i class="bi bi-shield-lock me-1"></i> Verify and continue
                </button>
            </form>
            <div class="d-flex align-items-center justify-content-between mt-2">
                <span id="resendText" class="small-muted">Click Resend code to send a verification code.</span>
                <button id="resendBtn" class="btn btn-link resend p-0" onclick="sendCode()">
                    <i class="bi bi-arrow-repeat"></i> Resend code
                </button>
            </div>
            
        </div>

        <p class="text-center mt-4">
            <span class="footer-text small-muted">
                <i class="bi bi-shield-check me-2"></i>Secured by email verification · Expires in 60 seconds
            </span>
        </p>
    </div>

    <!-- Sending overlay -->
    <div class="sending-overlay" id="sendingOverlay" style="display:none;">
        <div class="send-box">
            <div class="plane"></div>
            <div class="trail mb-2"></div>
            <div class="fw-semibold mb-1">Sending verification code</div>
            <div id="sendStatus" class="small-muted">Please wait...</div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Bootstrap validation helper
        (() => {
            'use strict'
            const forms = document.querySelectorAll('.needs-validation')
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
    </script>
</body>
</html>
