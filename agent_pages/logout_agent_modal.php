<style>
    /* ================================================
       LOGOUT MODAL — AGENT (Full Dark Theme)
       ================================================ */
    .logout-modal .modal-content {
        border: none;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(212, 175, 55, 0.1);
        background: linear-gradient(180deg, #0f172a 0%, #1a2332 100%);
    }

    .logout-modal-header {
        background: linear-gradient(135deg, rgba(212, 175, 55, 0.06) 0%, rgba(15, 23, 42, 0) 100%);
        padding: 2rem 2.5rem 0;
        text-align: center;
        position: relative;
    }

    .logout-modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 2rem;
        right: 2rem;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.25), transparent);
    }

    .logout-icon-ring {
        width: 72px;
        height: 72px;
        margin: 0 auto 1.1rem;
        border-radius: 50%;
        background: rgba(212, 175, 55, 0.08);
        border: 2px solid rgba(212, 175, 55, 0.2);
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
        border: 1.5px dashed rgba(212, 175, 55, 0.12);
        animation: logoutSpin 12s linear infinite;
    }

    @keyframes logoutSpin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .logout-icon-ring i {
        font-size: 1.85rem;
        color: #d4af37;
        filter: drop-shadow(0 0 8px rgba(212, 175, 55, 0.35));
    }

    .logout-modal-header h5 {
        color: #f1f5f9;
        font-weight: 700;
        font-size: 1.2rem;
        margin: 0 0 0.3rem;
        letter-spacing: 0.3px;
    }

    .logout-modal-header .logout-subtitle {
        color: rgba(148, 163, 184, 0.6);
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        padding-bottom: 1.5rem;
    }

    .logout-modal-body {
        padding: 1.5rem 2.5rem 1.25rem;
        text-align: center;
    }

    .logout-modal-body p {
        color: #94a3b8;
        font-size: 0.88rem;
        line-height: 1.7;
        margin: 0;
    }

    .logout-session-info {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 1.1rem;
        padding: 0.55rem 1.1rem;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        font-size: 0.78rem;
        color: #64748b;
    }

    .logout-session-info strong {
        color: #cbd5e1;
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
        padding: 0.5rem 2.5rem 2rem;
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
        border: 1.5px solid rgba(255, 255, 255, 0.1);
        background: rgba(255, 255, 255, 0.04);
        color: #94a3b8;
        cursor: pointer;
        transition: all 0.2s ease;
        letter-spacing: 0.2px;
    }

    .btn-logout-cancel:hover {
        background: rgba(255, 255, 255, 0.08);
        border-color: rgba(255, 255, 255, 0.18);
        color: #e2e8f0;
    }

    .btn-logout-confirm {
        flex: 1;
        padding: 0.7rem 1.25rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
        border: none;
        background: linear-gradient(135deg, #d4af37 0%, #b8941f 100%);
        color: #0f172a;
        cursor: pointer;
        transition: all 0.25s ease;
        letter-spacing: 0.2px;
        box-shadow: 0 2px 12px rgba(212, 175, 55, 0.25);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .btn-logout-confirm:hover {
        background: linear-gradient(135deg, #f4d03f 0%, #d4af37 100%);
        color: #0f172a;
        box-shadow: 0 4px 20px rgba(212, 175, 55, 0.4);
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
       AGENT LOGOUT TRANSITION OVERLAY
       Full dark + gold accent theme
       ================================================ */
    .agt-logout-overlay {
        position: fixed; inset: 0; z-index: 99999;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        pointer-events: none; opacity: 0;
        transition: opacity .4s ease;
        background:
            radial-gradient(circle at 50% 40%, rgba(212,175,55,.06) 0%, transparent 55%),
            linear-gradient(135deg, #0a0a0a 0%, #111111 50%, #0a0a0a 100%);
    }
    .agt-logout-overlay.active { pointer-events: all; opacity: 1; }

    /* Subtle dot-grid (like login/2FA pages) */
    .agt-logout-overlay::before {
        content: ''; position: absolute; inset: 0;
        background-image:
            radial-gradient(circle, rgba(212,175,55,.06) 1px, transparent 1px),
            radial-gradient(circle, rgba(37,99,235,.03) 1px, transparent 1px);
        background-size: 60px 60px, 90px 90px;
        background-position: 0 0, 30px 30px;
        pointer-events: none;
    }

    /* Icon circle – gold glow */
    .agt-logout-icon-wrap {
        width: 100px; height: 100px;
        border-radius: 50%;
        background: linear-gradient(135deg, rgba(184,148,31,.15) 0%, rgba(212,175,55,.08) 100%);
        border: 2px solid rgba(212,175,55,.25);
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 28px; position: relative; z-index: 1;
        opacity: 0; transform: scale(0.5);
        transition: opacity .4s ease .15s, transform .5s cubic-bezier(.34,1.56,.64,1) .15s;
    }
    .agt-logout-overlay.active .agt-logout-icon-wrap { opacity: 1; transform: scale(1); }

    .agt-logout-icon-wrap::before {
        content: ''; position: absolute; inset: -9px;
        border-radius: 50%;
        border: 1.5px dashed rgba(212,175,55,.12);
    }
    .agt-logout-icon-wrap i {
        font-size: 2.8rem; color: #d4af37;
        filter: drop-shadow(0 0 16px rgba(212,175,55,.5));
    }
    /* Expanding gold ring */
    .agt-logout-ring {
        position: absolute; inset: -9px;
        border-radius: 50%;
        border: 2px solid rgba(212,175,55,.35);
        opacity: 0; z-index: 0;
    }
    .agt-logout-overlay.active .agt-logout-ring {
        animation: agtRingExpand 1.4s ease-out .4s forwards;
    }

    .agt-logout-title {
        font-family: 'Inter', sans-serif;
        font-size: 1.6rem; font-weight: 700; color: #fff;
        text-shadow: 0 2px 12px rgba(0,0,0,.4);
        margin-bottom: 6px; position: relative; z-index: 1;
        opacity: 0; transform: translateY(14px);
        transition: opacity .4s ease .3s, transform .4s ease .3s;
    }
    .agt-logout-overlay.active .agt-logout-title { opacity: 1; transform: translateY(0); }

    .agt-logout-gold-line {
        width: 50px; height: 2px; margin: 0 auto 14px;
        background: linear-gradient(90deg, transparent, #d4af37, transparent);
        border-radius: 2px; position: relative; z-index: 1;
        opacity: 0;
        transition: opacity .3s ease .4s;
    }
    .agt-logout-overlay.active .agt-logout-gold-line { opacity: 1; }

    .agt-logout-subtitle {
        font-family: 'Inter', sans-serif;
        font-size: .9rem; color: #9ca4ab;
        margin-bottom: 30px; position: relative; z-index: 1;
        opacity: 0; transform: translateY(12px);
        transition: opacity .35s ease .44s, transform .35s ease .44s;
    }
    .agt-logout-overlay.active .agt-logout-subtitle { opacity: 1; transform: translateY(0); }

    /* Progress bar – gold gradient */
    .agt-logout-progress-track {
        width: 200px; height: 3px;
        background: rgba(255,255,255,.08);
        border-radius: 4px; overflow: hidden;
        margin-bottom: 14px; position: relative; z-index: 1;
        opacity: 0;
        transition: opacity .3s ease .5s;
    }
    .agt-logout-overlay.active .agt-logout-progress-track { opacity: 1; }
    .agt-logout-progress-fill {
        height: 100%; width: 0;
        background: linear-gradient(90deg, #b8941f, #d4af37, #f4d03f);
        border-radius: 4px;
        transition: width 1.3s cubic-bezier(.4,0,.2,1) .6s;
    }
    .agt-logout-overlay.active .agt-logout-progress-fill { width: 100%; }

    .agt-logout-label {
        font-family: 'Inter', sans-serif;
        font-size: .72rem; letter-spacing: 2.5px; text-transform: uppercase;
        color: rgba(212,175,55,.5); position: relative; z-index: 1;
        opacity: 0;
        transition: opacity .3s ease .55s;
    }
    .agt-logout-overlay.active .agt-logout-label { opacity: 1; }

    @keyframes agtRingExpand {
        0%   { transform: scale(1); opacity: .6; }
        100% { transform: scale(1.7); opacity: 0; }
    }

    .agt-logout-overlay.exit {
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
                <div class="logout-subtitle">Agent Portal</div>
            </div>
            <div class="logout-modal-body">
                <p>You're about to sign out of your agent account. Any unsaved changes will be lost and you'll need to log in again.</p>
                <div class="logout-session-info">
                    <span class="logout-session-dot"></span>
                    <span>Signed in as <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'Agent'); ?></strong></span>
                </div>
            </div>
            <div class="logout-modal-footer">
                <button type="button" class="btn-logout-cancel" data-bs-dismiss="modal">Stay Logged In</button>
                <a href="logout.php" class="btn-logout-confirm" id="agtLogoutConfirmBtn">
                    Sign Out <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Agent Logout Transition Overlay -->
<div class="agt-logout-overlay" id="agtLogoutOverlay">
    <div class="agt-logout-icon-wrap">
        <i class="bi bi-box-arrow-right"></i>
        <div class="agt-logout-ring"></div>
    </div>
    <div class="agt-logout-title">Signing Out…</div>
    <div class="agt-logout-gold-line"></div>
    <div class="agt-logout-subtitle">Ending your session securely</div>
    <div class="agt-logout-progress-track">
        <div class="agt-logout-progress-fill"></div>
    </div>
    <div class="agt-logout-label">Agent Portal</div>
</div>

<script>
(function() {
    var btn = document.getElementById('agtLogoutConfirmBtn');
    if (!btn) return;
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var href = btn.getAttribute('href') || 'logout.php';
        var overlay = document.getElementById('agtLogoutOverlay');
        if (!overlay) { window.location.href = href; return; }

        // Hide the modal instantly
        var modal = bootstrap.Modal.getInstance(document.getElementById('logoutModal'));
        if (modal) modal.hide();

        // Show overlay – CSS transitions kick in
        overlay.classList.add('active');

        // Progress bar fills 1.3s after .6s delay = 1.9s; navigate after buffer
        setTimeout(function() {
            overlay.classList.add('exit');
            setTimeout(function() {
                window.location.href = href;
            }, 380);
        }, 2100);
    });
})();
</script>