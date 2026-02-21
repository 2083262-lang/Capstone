// Extracted script for tour_requests.php
(function(){
    // UI helpers
    let alertContainer;
    const alertBox = (type, text) => {
        if (!alertContainer) alertContainer = document.querySelector('.content-wrapper .container-fluid');
        const container = document.createElement('div');
        container.className = `alert alert-${type}`;
        container.role = 'alert';
        container.textContent = text;
        alertContainer.prepend(container);
        setTimeout(() => container.remove(), 4000);
    };

    // Filtering
    let requestsTable, filterButtons, lastFilter = 'All';
    function setFilter(status){
        if (!requestsTable) requestsTable = document.getElementById('requestsTable');
        if (!filterButtons) filterButtons = document.querySelectorAll('.filter-btn');
        if (lastFilter === status) return; lastFilter = status;
        if (requestsTable) {
            const rows = requestsTable.tBodies[0].rows;
            const isAll = status === 'All';
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = (isAll || rows[i].getAttribute('data-status') === status) ? '' : 'none';
            }
        }
        if (filterButtons) filterButtons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-filter') === status));
    }

    // Modal lazy-loading logic
    window.modalsLoaded = false;
    function ensureModalsLoaded() {
        // If already injected (e.g., server-side include or prior fetch), skip network
        if (!window.modalsLoaded && document.getElementById('detailsModal')) {
            window.modalsLoaded = true;
            return Promise.resolve();
        }
        if (window.modalsLoaded) return Promise.resolve();
        return fetch('modals/tour_modals.html')
            .then(r => { if (!r.ok) throw new Error('failed'); return r.text(); })
            .then(html => { document.body.insertAdjacentHTML('beforeend', html); window.modalsLoaded = true; });
    }

    // Details modal behavior
    window.detailsModal = null;
    window.openDetails = function(tourId){
        ensureModalsLoaded().then(() => {
            if (!window.detailsModal) window.detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
            window.detailsModal.show();
            fetch('admin_tour_request_details.php?tour_id=' + encodeURIComponent(tourId))
                .then(r => r.json())
                .then(data => {
                    if(!data.success){ alertBox('danger', data.message || 'Failed to fetch details'); return; }
                    const d = data.data;
                    const status = d.request_status || 'Pending';
                    let badgeClass = 'bg-warning text-dark';
                    if (status === 'Confirmed') badgeClass = 'bg-primary';
                    else if (status === 'Completed') badgeClass = 'bg-success';
                    else if (status === 'Cancelled') badgeClass = 'bg-secondary';
                    else if (status === 'Rejected') badgeClass = 'bg-danger';

                    document.getElementById('detailsTitle').innerHTML = `<i class="fas fa-file-alt me-2"></i>Tour Request #${d.tour_id}`;
                    const addressParts = [];
                    if (d.StreetAddress) addressParts.push(d.StreetAddress);
                    if (d.City) addressParts.push(d.City);
                    if (d.State) addressParts.push(d.State);
                    if (d.ZIP) addressParts.push(d.ZIP);
                    const propertyAddress = addressParts.length ? addressParts.join(', ') : (d.property_address || 'N/A');

                    let actionButtons = '';
                    if (status === 'Pending') {
                        actionButtons = `
                            <div class="action-buttons">
                                <button class="btn btn-success" onclick="confirmAction('accept', ${d.tour_id})">
                                    <i class="fas fa-check me-2"></i>Confirm Tour
                                </button>
                                <button class="btn btn-danger" onclick="toggleRejectionSection()">
                                    <i class="fas fa-times me-2"></i>Reject Request
                                </button>
                            </div>
                            <div class="rejection-section" id="rejectionSection">
                                <h6 class="fw-bold mb-3"><i class="fas fa-exclamation-triangle me-2"></i>Rejection Reason</h6>
                                <textarea class="form-control mb-3" id="rejectionReason" rows="3" placeholder="Please provide a detailed reason for rejection..."></textarea>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-danger" onclick="confirmAction('reject', ${d.tour_id})">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Rejection
                                    </button>
                                    <button class="btn btn-secondary" onclick="toggleRejectionSection()">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </button>
                                </div>
                            </div>
                        `;
                    } else if (status === 'Confirmed') {
                        actionButtons = `
                            <div class="action-buttons">
                                <button class="btn btn-success" onclick="confirmAction('complete', ${d.tour_id})">
                                    <i class="fas fa-flag-checkered me-2"></i>Mark as Completed
                                </button>
                                <button class="btn btn-warning text-dark" onclick="toggleCancellationSection()">
                                    <i class="fas fa-ban me-2"></i>Cancel Tour
                                </button>
                            </div>
                            <div class="cancellation-section" id="cancellationSection">
                                <h6 class="fw-bold mb-3"><i class="fas fa-exclamation-triangle me-2"></i>Cancellation Reason</h6>
                                <textarea class="form-control mb-3" id="cancellationReason" rows="3" placeholder="Please provide a detailed reason for cancellation..."></textarea>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-danger" onclick="confirmAction('cancel', ${d.tour_id})">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Cancellation
                                    </button>
                                    <button class="btn btn-secondary" onclick="toggleCancellationSection()">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </button>
                                </div>
                            </div>
                        `;
                    }

                    document.getElementById('detailsBody').innerHTML = `
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <div class="detail-card">
                                    <div class="detail-label"><i class="fas fa-info-circle"></i>Current Status</div>
                                    <div class="detail-value">
                                        <span class="status-badge-large ${badgeClass}">${escapeHtml(status)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="detail-card">
                                    <div class="detail-label"><i class="fas fa-user"></i>Client Information</div>
                                    <div class="detail-value">
                                        <div class="mb-2"><strong>${escapeHtml(d.user_name || 'N/A')}</strong></div>
                                        <div class="small text-muted mb-1">
                                            <i class="fas fa-envelope me-2"></i>${escapeHtml(d.user_email || 'N/A')}
                                        </div>
                                        ${d.user_phone ? `<div class="small text-muted">
                                            <i class="fas fa-phone me-2"></i>${escapeHtml(d.user_phone)}
                                        </div>` : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-card">
                                    <div class="detail-label"><i class="fas fa-building"></i>Property Details</div>
                                    <div class="detail-value">
                                        <div class="mb-2">${escapeHtml(propertyAddress)}</div>
                                        <div class="small text-muted">
                                            <i class="fas fa-hashtag me-2"></i>Property ID: ${d.property_id}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="detail-card">
                                    <div class="detail-label"><i class="far fa-calendar-alt"></i>Tour Date</div>
                                    <div class="detail-value">
                                        <div class="mb-1">${escapeHtml(d.tour_date_fmt || 'N/A')}</div>
                                        <div class="small text-muted">
                                            <i class="far fa-clock me-2"></i>${escapeHtml(d.tour_time_fmt || 'N/A')}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-card">
                                    <div class="detail-label"><i class="fas fa-calendar-plus"></i>Requested On</div>
                                    <div class="detail-value">${escapeHtml(d.requested_at_fmt || 'N/A')}</div>
                                </div>
                            </div>
                        </div>
                        
                        ${d.message ? `
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <div class="detail-card">
                                    <div class="detail-label"><i class="fas fa-comment-dots"></i>Client Message</div>
                                    <div class="detail-value" style="white-space:pre-wrap;">${escapeHtml(d.message)}</div>
                                </div>
                            </div>
                        </div>` : ''}
                        
                        ${d.decision_reason ? `
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <div class="detail-card" style="background:#fff3cd;border-color:#ffc107;">
                                    <div class="detail-label"><i class="fas fa-asterisk"></i>Decision Reason</div>
                                    <div class="detail-value" style="white-space:pre-wrap;">${escapeHtml(d.decision_reason)}</div>
                                </div>
                            </div>
                        </div>` : ''}
                        
                        ${actionButtons}
                    `;

                    window.currentTourId = d.tour_id;
                })
                .catch(() => alertBox('danger', 'Network error fetching details'));
        }).catch(() => alertBox('danger', 'Failed to load UI components'));
    };

    // Rejection/cancellation toggles
    window.toggleRejectionSection = function(){ const s = document.getElementById('rejectionSection'); if (!s) return; s.classList.toggle('show'); if (s.classList.contains('show')) document.getElementById('rejectionReason').focus(); };
    window.toggleCancellationSection = function(){ const s = document.getElementById('cancellationSection'); if (!s) return; s.classList.toggle('show'); if (s.classList.contains('show')) document.getElementById('cancellationReason').focus(); };

    // Confirmation flow
    window.confirmAction = function(action, tourId){
        ensureModalsLoaded().then(()=>{
            let confirmMessage = '';
            let actionTitle = '';
            let danger = false;
            switch(action){
                case 'accept': confirmMessage='Confirming will notify the client and reserve the slot.'; actionTitle='Confirm Tour Request'; break;
                case 'reject': {
                    const rr = document.getElementById('rejectionReason') ? document.getElementById('rejectionReason').value.trim() : '';
                    if (!rr) { alertBox('warning','Please provide a reason for rejection.'); return; }
                    confirmMessage='Rejecting will notify the client with the provided reason.'; actionTitle='Reject Tour Request'; danger=true; break;
                }
                case 'cancel': {
                    const cr = document.getElementById('cancellationReason') ? document.getElementById('cancellationReason').value.trim() : '';
                    if (!cr) { alertBox('warning','Please provide a reason for cancellation.'); return; }
                    confirmMessage='Cancelling will notify the client with the provided reason and free the slot.'; actionTitle='Cancel Confirmed Tour'; danger=true; break;
                }
                case 'complete': confirmMessage='Marking completed will finalize this tour and notify the client.'; actionTitle='Mark Tour as Completed'; break;
            }

            document.getElementById('confirmModalTitle').textContent = actionTitle;
            document.getElementById('confirmModalBody').textContent = confirmMessage;
            const confirmBtn = document.getElementById('confirmModalConfirmBtn');
            confirmBtn.className = danger ? 'btn btn-danger' : 'btn btn-primary';
            confirmBtn.onclick = function(){ if (window.confirmationModal) window.confirmationModal.hide(); confirmBtn.disabled=true; executeAction(action,tourId); setTimeout(()=>confirmBtn.disabled=false,1500); };
            if (!window.confirmationModal) window.confirmationModal = new bootstrap.Modal(document.getElementById('confirmModal'));
            window.confirmationModal.show();
        }).catch(()=>alertBox('danger','Failed to load confirmation dialog'));
    };

    // Action execution
    const ACTION_MAP = { 'accept':{endpoint:'admin_tour_request_accept.php', status:'Confirmed'}, 'reject':{endpoint:'admin_tour_request_reject.php', status:'Rejected'}, 'cancel':{endpoint:'admin_tour_request_cancel.php', status:'Cancelled'}, 'complete':{endpoint:'admin_tour_request_complete.php', status:'Completed'} };
    const loadingTemplate = `<div class="text-center py-5"><div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status"><span class="visually-hidden">Loading...</span></div><div class="mt-3 fw-semibold">Processing your request...</div></div>`;

    window.executeAction = function(action,tourId){
        const info = ACTION_MAP[action]; if (!info) return alertBox('danger','Invalid action');
        const body = { tour_id: tourId };
        if (action==='reject') body.reason = document.getElementById('rejectionReason') ? document.getElementById('rejectionReason').value.trim() : '';
        if (action==='cancel') body.reason = document.getElementById('cancellationReason') ? document.getElementById('cancellationReason').value.trim() : '';
        const detailsBody = document.getElementById('detailsBody'); const original = detailsBody ? detailsBody.innerHTML : '';
        if (detailsBody) detailsBody.innerHTML = loadingTemplate;
        fetch(info.endpoint, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams(body) })
            .then(r=>r.json()).then(res=>{
                if (res.success) { alertBox('success', res.message || 'Action completed successfully'); updateRowStatus(tourId, info.status); if (window.detailsModal) window.detailsModal.hide(); setTimeout(()=>location.reload(),1200); }
                else { alertBox('danger', res.message || 'Action failed'); if (detailsBody) detailsBody.innerHTML = original; }
            }).catch(()=>{ alertBox('danger','Network error occurred'); if (detailsBody) detailsBody.innerHTML = original; });
    };

    // Update row
    window.updateRowStatus = function(tourId,newStatus){ const tr = document.getElementById('row-'+tourId); if (!tr) return; tr.setAttribute('data-status', newStatus); const s = tr.querySelector('.status-badge'); if (s){ s.textContent = newStatus; s.className = 'status-badge ' + ( newStatus==='Confirmed' ? 'badge-confirmed' : newStatus==='Completed' ? 'badge-completed' : newStatus==='Cancelled' ? 'badge-cancelled' : newStatus==='Rejected' ? 'badge-rejected' : 'badge-pending' ); } };

    // Escape helper
    window.escapeHtml = function(s){ const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}; return String(s||'').replace(/[&<>"']/g, c => map[c] || c); };

    // DOM ready init
    function init() { try{ setFilter('All'); const tt = document.querySelectorAll('[data-bs-toggle="tooltip"]'); if (tt.length) tt.forEach(el=>new bootstrap.Tooltip(el)); }catch(e){}
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {passive:true}); else init();

    // Expose setFilter to global (used by inline button onclick attributes)
    window.setFilter = setFilter;
})();
