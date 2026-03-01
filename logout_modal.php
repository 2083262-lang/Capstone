<style>
    /* ================================================
       LOGOUT MODAL — ADMIN (Consistent Theme)
       ================================================ */
    .logout-modal .modal-content {
        border: none;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(212, 175, 55, 0.08);
        background: #fff;
    }

    .logout-modal-header {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        padding: 2rem 2.5rem 1.75rem;
        text-align: center;
        position: relative;
    }

    .logout-modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, transparent, #d4af37, #f4d03f, #d4af37, transparent);
    }

    .logout-icon-ring {
        width: 70px;
        height: 70px;
        margin: 0 auto 1.1rem;
        border-radius: 50%;
        background: rgba(212, 175, 55, 0.1);
        border: 2px solid rgba(212, 175, 55, 0.25);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
    }

    .logout-icon-ring::before {
        content: '';
        position: absolute;
        inset: -6px;
        border-radius: 50%;
        border: 1.5px dashed rgba(212, 175, 55, 0.15);
        animation: logoutSpin 12s linear infinite;
    }

    @keyframes logoutSpin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .logout-icon-ring i {
        font-size: 1.75rem;
        color: #d4af37;
        filter: drop-shadow(0 0 6px rgba(212, 175, 55, 0.3));
    }

    .logout-modal-header h5 {
        color: #fff;
        font-weight: 700;
        font-size: 1.2rem;
        margin: 0 0 0.3rem;
        letter-spacing: 0.3px;
    }

    .logout-modal-header .logout-subtitle {
        color: rgba(255, 255, 255, 0.45);
        font-size: 0.75rem;
        font-weight: 500;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }

    .logout-modal-body {
        padding: 1.75rem 2.5rem;
        text-align: center;
    }

    .logout-modal-body p {
        color: #64748b;
        font-size: 0.9rem;
        line-height: 1.7;
        margin: 0;
    }

    .logout-session-info {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 1.1rem;
        padding: 0.55rem 1.1rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.78rem;
        color: #94a3b8;
    }

    .logout-session-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 6px rgba(34, 197, 94, 0.5);
        flex-shrink: 0;
    }

    .logout-modal-footer {
        padding: 0 2.5rem 2rem;
        display: flex;
        gap: 0.75rem;
        justify-content: center;
    }

    .btn-logout-cancel {
        flex: 1;
        padding: 0.7rem 1.25rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
        border: 1.5px solid #e2e8f0;
        background: #fff;
        color: #475569;
        cursor: pointer;
        transition: all 0.2s ease;
        letter-spacing: 0.2px;
    }

    .btn-logout-cancel:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
    }

    .btn-logout-confirm {
        flex: 1;
        padding: 0.7rem 1.25rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
        border: 1px solid rgba(37, 99, 235, 0.4);
        background: #0f172a;
        color: #e2e8f0;
        cursor: pointer;
        transition: all 0.3s ease;
        letter-spacing: 0.2px;
        box-shadow: 0 0 0 rgba(37, 99, 235, 0), inset 0 0 0 rgba(37, 99, 235, 0);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .btn-logout-confirm:hover {
        color: #1e40af;
        border-color: #2563eb;
        background: rgba(37, 99, 235, 0.08);
        box-shadow: 0 4px 16px rgba(37, 99, 235, 0.2), inset 0 0 20px rgba(37, 99, 235, 0.1);
        transform: translateY(-1px);
    }

    .btn-logout-confirm i {
        font-size: 0.85rem;
        transition: transform 0.2s ease;
    }

    .btn-logout-confirm:hover i {
        transform: translateX(2px);
    }

    /* ================================================
       ADMIN LOGOUT TRANSITION OVERLAY
       Light / professional theme matching the modal
       ================================================ */
    .adm-logout-overlay {
        position: fixed; inset: 0;
        z-index: 99999;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        pointer-events: none;
        opacity: 0;
        transition: opacity .4s ease;
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
    }
    .adm-logout-overlay.active {
        pointer-events: all;
        opacity: 1;
    }

    /* Animated icon */
    .adm-logout-icon-wrap {
        width: 96px; height: 96px;
        border-radius: 50%;
        background: rgba(212, 175, 55, 0.08);
        border: 2px solid rgba(212, 175, 55, 0.2);
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 28px;
        position: relative;
        opacity: 0;
        transform: scale(0.5);
        transition: opacity .4s ease .15s, transform .5s cubic-bezier(.34,1.56,.64,1) .15s;
    }
    .adm-logout-overlay.active .adm-logout-icon-wrap {
        opacity: 1; transform: scale(1);
    }
    .adm-logout-icon-wrap::before {
        content: ''; position: absolute; inset: -8px;
        border-radius: 50%;
        border: 2px dashed rgba(212,175,55,.15);
    }
    .adm-logout-icon-wrap i {
        font-size: 2.5rem;
        color: #d4af37;
        filter: drop-shadow(0 0 12px rgba(212,175,55,.4));
    }
    /* Animated ring expand */
    .adm-logout-ring {
        position: absolute; inset: -8px;
        border-radius: 50%;
        border: 2px solid rgba(212,175,55,.3);
        opacity: 0;
    }
    .adm-logout-overlay.active .adm-logout-ring {
        animation: admLogoutRingPulse 1.4s ease-out .4s forwards;
    }

    .adm-logout-title {
        font-family: 'Inter', sans-serif;
        font-size: 1.5rem; font-weight: 700;
        color: #f1f5f9;
        margin-bottom: 6px;
        opacity: 0; transform: translateY(12px);
        transition: opacity .4s ease .3s, transform .4s ease .3s;
    }
    .adm-logout-overlay.active .adm-logout-title {
        opacity: 1; transform: translateY(0);
    }

    .adm-logout-subtitle {
        font-family: 'Inter', sans-serif;
        font-size: .88rem; color: #94a3b8;
        margin-bottom: 28px;
        opacity: 0; transform: translateY(12px);
        transition: opacity .35s ease .42s, transform .35s ease .42s;
    }
    .adm-logout-overlay.active .adm-logout-subtitle {
        opacity: 1; transform: translateY(0);
    }

    /* Gold progress bar */
    .adm-logout-progress-track {
        width: 200px; height: 3px;
        background: rgba(255,255,255,.1);
        border-radius: 4px; overflow: hidden;
        margin-bottom: 12px;
        opacity: 0;
        transition: opacity .3s ease .5s;
    }
    .adm-logout-overlay.active .adm-logout-progress-track { opacity: 1; }
    .adm-logout-progress-fill {
        height: 100%; width: 0;
        background: linear-gradient(90deg, #b8941f, #d4af37, #f4d03f);
        border-radius: 4px;
        transition: width 1.3s cubic-bezier(.4,0,.2,1) .6s;
    }
    .adm-logout-overlay.active .adm-logout-progress-fill { width: 100%; }

    .adm-logout-label {
        font-family: 'Inter', sans-serif;
        font-size: .72rem; letter-spacing: 2px; text-transform: uppercase;
        color: rgba(212,175,55,.6);
        opacity: 0;
        transition: opacity .3s ease .55s;
    }
    .adm-logout-overlay.active .adm-logout-label { opacity: 1; }

    /* Gold line separator */
    .adm-logout-gold-line {
        width: 50px; height: 2px; margin: 0 auto 20px;
        background: linear-gradient(90deg, transparent, #d4af37, transparent);
        border-radius: 2px;
        opacity: 0;
        transition: opacity .3s ease .48s;
    }
    .adm-logout-overlay.active .adm-logout-gold-line { opacity: 1; }

    @keyframes admLogoutRingPulse {
        0%   { transform: scale(1); opacity: .6; }
        100% { transform: scale(1.6); opacity: 0; }
    }

    /* Final fade-out before navigation */
    .adm-logout-overlay.exit {
        opacity: 0;
        transition: opacity .35s ease;
    }
</style>

<div class="modal fade logout-modal" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
        <div class="modal-content">
            <div class="logout-modal-header">
                <div class="logout-icon-ring">
                    <i class="bi bi-power"></i>
                </div>
                <h5 id="logoutModalLabel">End Your Session?</h5>
                <div class="logout-subtitle">Admin Portal</div>
            </div>
            <div class="logout-modal-body">
                <p>You're about to sign out of your admin account. Any unsaved changes will be lost and you'll need to log in again.</p>
                <div class="logout-session-info">
                    <span class="logout-session-dot"></span>
                    <span>Signed in as <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></strong></span>
                </div>
            </div>
            <div class="logout-modal-footer">
                <button type="button" class="btn-logout-cancel" data-bs-dismiss="modal">Stay Logged In</button>
                <a href="logout.php" class="btn-logout-confirm" id="admLogoutConfirmBtn">
                    Sign Out <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Admin Logout Transition Overlay -->
<div class="adm-logout-overlay" id="admLogoutOverlay">
    <div class="adm-logout-icon-wrap">
        <i class="bi bi-box-arrow-right"></i>
        <div class="adm-logout-ring"></div>
    </div>
    <div class="adm-logout-title">Signing Out…</div>
    <div class="adm-logout-gold-line"></div>
    <div class="adm-logout-subtitle">Ending your admin session securely</div>
    <div class="adm-logout-progress-track">
        <div class="adm-logout-progress-fill"></div>
    </div>
    <div class="adm-logout-label">Admin Portal</div>
</div>

<script>
(function() {
    var btn = document.getElementById('admLogoutConfirmBtn');
    if (!btn) return;
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var href = btn.getAttribute('href') || 'logout.php';
        var overlay = document.getElementById('admLogoutOverlay');
        if (!overlay) { window.location.href = href; return; }

        // Hide the modal instantly
        var modal = bootstrap.Modal.getInstance(document.getElementById('logoutModal'));
        if (modal) modal.hide();

        // Show overlay and trigger CSS transitions
        overlay.classList.add('active');

        // Progress bar fills 1.3s after .6s delay = 1.9s, then small buffer
        setTimeout(function() {
            overlay.classList.add('exit');
            setTimeout(function() {
                window.location.href = href;
            }, 380);
        }, 2100);
    });
})();
</script>