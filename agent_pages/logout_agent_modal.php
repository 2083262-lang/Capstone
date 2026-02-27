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
                <a href="logout.php" class="btn-logout-confirm">
                    Sign Out <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</div>