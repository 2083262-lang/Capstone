/* ===================================================================
   Agent Property Modals – JavaScript
   Used by: view_agent_property.php
   Requires: Bootstrap 5, property_id (global), property_images,
             floor_images (globals set in the page)
   =================================================================== */

// =====================================================================
// Amenity Checkbox Toggle Styling
// =====================================================================
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.amenity-checkbox-item input[type="checkbox"]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            this.closest('.amenity-checkbox-item').classList.toggle('selected', this.checked);
        });
    });
});

// =====================================================================
// Edit Property Form
// =====================================================================
(function () {
    const form = document.getElementById('editPropertyForm');
    if (!form) return;
    const alertBox = document.getElementById('updateAlert');
    const descTextarea = form.querySelector('[name="ListingDescription"]');
    const charCountEl = document.getElementById('charCount');

    // Track deletions to process on save (custom prefixed names to avoid conflicts)
    window.__modEditProp_deletedPhotos = [];
    window.__modEditProp_deletedFloorImages = [];
    window.__modEditProp_removedFloors = []; // Track entire floors to remove
    let __modEditProp_saveSuccessful = false;

    // Handle modal cancel/close - reload if there are pending deletions
    const editModal = document.getElementById('editPropertyModal');
    if (editModal) {
        editModal.addEventListener('hide.bs.modal', function (e) {
            // Only reload if save was NOT successful and there are pending changes
            if (!__modEditProp_saveSuccessful) {
                const hasPendingChanges = 
                    (window.__modEditProp_deletedPhotos && window.__modEditProp_deletedPhotos.length > 0) ||
                    (window.__modEditProp_deletedFloorImages && window.__modEditProp_deletedFloorImages.length > 0) ||
                    (window.__modEditProp_removedFloors && window.__modEditProp_removedFloors.length > 0);
                
                // If closing without saving and there are pending changes, reload the page
                if (hasPendingChanges) {
                    setTimeout(() => window.location.reload(), 100);
                }
            }
        });
    }

    function showAlert(ok, msg) {
        alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');
        alertBox.classList.add(ok ? 'alert-success' : 'alert-danger');
        alertBox.innerHTML = '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i> ' + msg;
    }

    if (descTextarea && charCountEl) {
        descTextarea.addEventListener('input', function () {
            charCountEl.textContent = this.value.length;
            charCountEl.style.color = this.value.length > 1000 ? '#ef4444' : this.value.length > 800 ? '#fbbf24' : 'var(--gray-500)';
        });
        charCountEl.textContent = descTextarea.value.length;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!form.checkValidity()) { form.classList.add('was-validated'); showAlert(false, 'Please fill in all required fields.'); return; }
        const fd = new FormData(form);
        const price = parseFloat(fd.get('ListingPrice') || '0');
        const desc = String(fd.get('ListingDescription') || '');
        const dateStr = String(fd.get('ListingDate') || '');
        const today = new Date().toISOString().slice(0, 10);
        let msg = '';
        if (price < 0 || isNaN(price)) msg = 'Price must be positive.';
        else if (!dateStr || dateStr > today) msg = 'Date cannot be in the future.';
        else if (desc.trim().length < 20) msg = 'Description must be at least 20 characters.';
        if (msg) { showAlert(false, msg); return; }

        const submitBtn = form.querySelector('button[type="submit"]');
        const origHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        // Build URLSearchParams manually to support multiple values (amenities[])
        const params = new URLSearchParams();
        for (const [key, value] of fd.entries()) {
            params.append(key, value);
        }

        // Add deleted photos tracking
        if (window.__modEditProp_deletedPhotos && window.__modEditProp_deletedPhotos.length > 0) {
            params.append('deleted_photos', JSON.stringify(window.__modEditProp_deletedPhotos));
        }
        if (window.__modEditProp_deletedFloorImages && window.__modEditProp_deletedFloorImages.length > 0) {
            params.append('deleted_floor_images', JSON.stringify(window.__modEditProp_deletedFloorImages));
        }
        if (window.__modEditProp_removedFloors && window.__modEditProp_removedFloors.length > 0) {
            params.append('removed_floors', JSON.stringify(window.__modEditProp_removedFloors));
        }

        fetch('update_property_process.php', {
            method: 'POST',
            body: params,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
            .then(r => r.json())
            .then(data => {
                if (data?.success) {
                    __modEditProp_saveSuccessful = true; // Mark as successful to prevent cancel reload
                    showAlert(true, data.message || 'Property updated successfully!');
                    setTimeout(() => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('editPropertyModal'));
                        if (modal) modal.hide();
                        setTimeout(() => window.location.reload(), 300);
                    }, 800);
                }
                else { showAlert(false, data?.message || 'Update failed.'); submitBtn.disabled = false; submitBtn.innerHTML = origHtml; }
            })
            .catch(() => { showAlert(false, 'Network error occurred.'); submitBtn.disabled = false; submitBtn.innerHTML = origHtml; });
    });
})();

// =====================================================================
// Featured Photos Management
// =====================================================================
(function () {
    const propId = window._propertyId;
    const grid = document.getElementById('photosGrid');
    const uploadInput = document.getElementById('photoUploadInput');
    const orderBtn = document.getElementById('savePhotoOrderBtn');
    const alertEl = document.getElementById('photosAlert');

    if (!grid) return;

    function showPhotosAlert(ok, msg) {
        alertEl.classList.remove('d-none', 'alert-success', 'alert-danger');
        alertEl.classList.add(ok ? 'alert-success' : 'alert-danger');
        alertEl.innerHTML = '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i> ' + msg;
    }

    function getOrder() { return Array.from(grid.querySelectorAll('[data-url]')).map(el => el.getAttribute('data-url')); }

    function reflow() {
        const items = Array.from(grid.querySelectorAll('[data-url]'));
        items.forEach((wrap, idx) => {
            const left = wrap.querySelector('.btn-move-left');
            const right = wrap.querySelector('.btn-move-right');
            if (left) left.disabled = (idx === 0);
            if (right) right.disabled = (idx === items.length - 1);
            wrap.querySelectorAll('.cover-badge-small').forEach(b => b.remove());
            if (idx === 0) {
                const badge = document.createElement('div');
                badge.className = 'cover-badge-small';
                badge.innerHTML = '<i class="bi bi-star-fill"></i> Cover';
                wrap.appendChild(badge);
            }
        });
    }

    grid.addEventListener('click', function (e) {
        const col = e.target.closest('[data-url]');
        if (!col) return;
        const url = col.getAttribute('data-url');
        if (e.target.closest('.btn-move-left')) {
            const prev = col.previousElementSibling;
            if (prev) { col.parentNode.insertBefore(col, prev); reflow(); }
        } else if (e.target.closest('.btn-move-right')) {
            const next = col.nextElementSibling;
            if (next) { col.parentNode.insertBefore(next, col); reflow(); }
        } else if (e.target.closest('.btn-set-cover')) {
            const first = grid.querySelector('[data-url]');
            if (first !== col) { grid.insertBefore(col, first); reflow(); saveOrder('Cover photo set.'); }
        } else if (e.target.closest('.btn-delete-photo')) {
            deletePhoto(url, col);
        }
    });
    reflow();

    function saveOrder(successMsg) {
        fetch('reorder_property_images.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ property_id: String(propId), order: JSON.stringify(getOrder()) })
        }).then(r => r.json()).then(data => {
            if (data?.success) { showPhotosAlert(true, successMsg || 'Photo order saved.'); reflow(); }
            else { showPhotosAlert(false, data?.message || 'Failed to save order.'); }
        }).catch(() => showPhotosAlert(false, 'Network error.'));
    }

    function deletePhoto(url, node) {
        // Mark for deletion (will delete on save)
        if (!window.__modEditProp_deletedPhotos) window.__modEditProp_deletedPhotos = [];
        window.__modEditProp_deletedPhotos.push(url);
        node.remove();
        showPhotosAlert(true, 'Photo marked for deletion. Save changes to confirm.');
        reflow();
    }

    if (orderBtn) orderBtn.addEventListener('click', () => saveOrder());

    if (uploadInput) {
        uploadInput.addEventListener('change', function () {
            const files = Array.from(this.files || []);
            if (!files.length) return;

            // Client-side validation
            const maxSize = 25 * 1024 * 1024; // 25MB per featured photo
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            const maxPhotos = 20;
            const currentCount = grid.querySelectorAll('[data-url]').length;

            if (currentCount + files.length > maxPhotos) {
                showPhotosAlert(false, 'Maximum ' + maxPhotos + ' featured photos allowed. You currently have ' + currentCount + '.');
                this.value = '';
                return;
            }

            const invalid = files.filter(f => !allowedTypes.includes(f.type));
            if (invalid.length > 0) {
                showPhotosAlert(false, 'Invalid file type: ' + invalid.map(f => f.name).join(', ') + '. Only JPEG, PNG, GIF allowed.');
                this.value = '';
                return;
            }

            const tooBig = files.filter(f => f.size > maxSize);
            if (tooBig.length > 0) {
                showPhotosAlert(false, 'File(s) too large: ' + tooBig.map(f => f.name).join(', ') + '. Max 25MB each.');
                this.value = '';
                return;
            }

            const fd = new FormData();
            fd.append('property_id', String(propId));
            files.forEach(f => fd.append('images[]', f));
            fetch('upload_property_image.php', { method: 'POST', body: fd })
                .then(r => r.json()).then(data => {
                    if (data?.success && Array.isArray(data.photos)) {
                        data.photos.forEach(photo => {
                            const item = document.createElement('div');
                            item.className = 'edit-photo-item';
                            item.setAttribute('data-url', photo.url);
                            item.innerHTML = `
                                <img src="../${photo.url}" alt="Photo">
                                <div class="edit-photo-overlay">
                                    <button type="button" class="edit-photo-btn btn-move-left" title="Move left"><i class="bi bi-arrow-left"></i></button>
                                    <button type="button" class="edit-photo-btn btn-set-cover" title="Set as cover"><i class="bi bi-star"></i></button>
                                    <button type="button" class="edit-photo-btn btn-move-right" title="Move right"><i class="bi bi-arrow-right"></i></button>
                                    <button type="button" class="edit-photo-btn btn-delete btn-delete-photo" title="Delete"><i class="bi bi-trash"></i></button>
                                </div>`;
                            grid.appendChild(item);
                        });
                        showPhotosAlert(true, data.photos.length + ' photo(s) uploaded.');
                        reflow();
                        uploadInput.value = '';
                    } else { showPhotosAlert(false, data?.message || 'Upload failed.'); }
                }).catch(() => showPhotosAlert(false, 'Network error.'));
        });
    }
})();

// =====================================================================
// Floor Images Management
// =====================================================================

// Track the current state of floors
let _floorState = {};

(function () {
    // Initialize floor state from the server-rendered data
    if (window._floorImagesData && typeof window._floorImagesData === 'object') {
        Object.keys(window._floorImagesData).forEach(key => {
            _floorState[parseInt(key)] = (window._floorImagesData[key] || []).slice();
        });
    }
})();

function showFloorAlert(ok, msg) {
    const alertEl = document.getElementById('floorImagesAlert');
    if (!alertEl) return;
    alertEl.classList.remove('d-none', 'alert-success', 'alert-danger');
    alertEl.classList.add(ok ? 'alert-success' : 'alert-danger');
    alertEl.innerHTML = '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i> ' + msg;
    setTimeout(() => alertEl.classList.add('d-none'), 5000);
}

function switchFloorTab(floorNum) {
    document.querySelectorAll('.floor-manager-tab').forEach(tab => {
        tab.classList.toggle('active', parseInt(tab.dataset.floor) === floorNum);
    });
    document.querySelectorAll('.floor-panel').forEach(panel => {
        panel.classList.toggle('active', parseInt(panel.dataset.floor) === floorNum);
    });
}

function updateFloorCount(floorNum) {
    const countEl = document.getElementById('floorCount_' + floorNum);
    const grid = document.getElementById('floorGrid_' + floorNum);
    if (countEl && grid) {
        countEl.textContent = grid.querySelectorAll('.floor-photo-item').length;
    }
}

function addNewFloor() {
    const propId = window._propertyId;
    const tabsContainer = document.getElementById('floorManagerTabs');
    const panelsContainer = document.getElementById('floorPanelsContainer');

    // Determine next floor number
    const existingTabs = tabsContainer.querySelectorAll('.floor-manager-tab[data-floor]');
    let maxFloor = 0;
    existingTabs.forEach(tab => {
        const fn = parseInt(tab.dataset.floor);
        if (fn > maxFloor) maxFloor = fn;
    });
    const newFloor = maxFloor + 1;

    if (newFloor > 10) {
        showFloorAlert(false, 'Maximum of 10 floors allowed.');
        return;
    }

    // Remove empty state message if present
    const emptyState = tabsContainer.querySelector('.floor-empty-state');
    if (emptyState) emptyState.remove();

    // Create tab button (insert before Add Floor button)
    const addBtn = document.getElementById('addFloorBtn');
    const tab = document.createElement('button');
    tab.type = 'button';
    tab.className = 'floor-manager-tab';
    tab.dataset.floor = newFloor;
    tab.onclick = function () { switchFloorTab(newFloor); };
    tab.innerHTML = `<i class="bi bi-layers"></i> Floor ${newFloor} <span class="floor-count" id="floorCount_${newFloor}">0</span>`;
    tabsContainer.insertBefore(tab, addBtn);

    // Create panel
    const panel = document.createElement('div');
    panel.className = 'floor-panel';
    panel.id = 'floorPanel_' + newFloor;
    panel.dataset.floor = newFloor;
    panel.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span style="font-size: 0.85rem; font-weight: 600; color: var(--white);">
                <i class="bi bi-layers me-1" style="color: var(--blue-light);"></i> Floor ${newFloor} Photos
            </span>
            <button type="button" class="remove-floor-btn" onclick="removeFloor(${newFloor})" title="Remove this floor and all its images">
                <i class="bi bi-trash me-1"></i> Remove Floor
            </button>
        </div>
        <div class="floor-photos-grid" id="floorGrid_${newFloor}"></div>
        <label class="floor-upload-area w-100" for="floorUpload_${newFloor}">
            <i class="bi bi-cloud-arrow-up d-block"></i>
            <div style="font-weight: 600; color: var(--white); font-size: 0.85rem;">Upload Floor ${newFloor} Images</div>
            <div style="font-size: 0.75rem; color: var(--gray-500);">JPEG, PNG, GIF up to 10MB each</div>
        </label>
        <input type="file" id="floorUpload_${newFloor}" accept="image/*" multiple style="display: none;"
               onchange="uploadFloorImages(${newFloor}, this)">
    `;
    panelsContainer.appendChild(panel);

    // Initialize state
    _floorState[newFloor] = [];

    // Switch to the new floor tab
    switchFloorTab(newFloor);
    showFloorAlert(true, 'Floor ' + newFloor + ' added. Upload images to save.');
}

function removeFloor(floorNum) {
    // Check if this floor has any images in the grid
    const panel = document.getElementById('floorPanel_' + floorNum);
    const hasImages = panel && panel.querySelectorAll('.floor-photo-item').length > 0;

    // Mark floor for removal on save
    if (!window.__modEditProp_removedFloors) window.__modEditProp_removedFloors = [];
    window.__modEditProp_removedFloors.push(floorNum);

    // Remove tab and panel from UI
    const tab = document.querySelector('.floor-manager-tab[data-floor="' + floorNum + '"]');
    if (tab) tab.remove();
    if (panel) panel.remove();

    delete _floorState[floorNum];

    // Renumber remaining tabs and panels
    renumberFloors();

    // Activate the first remaining floor (if any)
    const remainingTabs = document.querySelectorAll('.floor-manager-tab[data-floor]');
    if (remainingTabs.length > 0) {
        switchFloorTab(parseInt(remainingTabs[0].dataset.floor));
    } else {
        // Show empty state
        const tabsContainer = document.getElementById('floorManagerTabs');
        const addBtn = document.getElementById('addFloorBtn');
        const emptyDiv = document.createElement('div');
        emptyDiv.className = 'floor-empty-state w-100';
        emptyDiv.innerHTML = '<i class="bi bi-building"></i><div>No floor images yet. Click "Add Floor" to get started.</div>';
        tabsContainer.insertBefore(emptyDiv, addBtn);
    }

    showFloorAlert(true, 'Floor ' + floorNum + ' marked for removal. Save changes to confirm.');
}

function renumberFloors() {
    const tabsContainer = document.getElementById('floorManagerTabs');
    const panelsContainer = document.getElementById('floorPanelsContainer');
    const tabs = Array.from(tabsContainer.querySelectorAll('.floor-manager-tab[data-floor]'));
    const newState = {};

    tabs.forEach((tab, idx) => {
        const newNum = idx + 1;
        const oldNum = parseInt(tab.dataset.floor);

        // Update tab
        tab.dataset.floor = newNum;
        tab.onclick = function () { switchFloorTab(newNum); };
        const countSpan = tab.querySelector('.floor-count');
        if (countSpan) countSpan.id = 'floorCount_' + newNum;
        tab.innerHTML = `<i class="bi bi-layers"></i> Floor ${newNum} <span class="floor-count" id="floorCount_${newNum}">${countSpan ? countSpan.textContent : '0'}</span>`;

        // Update panel
        const panel = document.getElementById('floorPanel_' + oldNum);
        if (panel) {
            panel.id = 'floorPanel_' + newNum;
            panel.dataset.floor = newNum;
        }

        if (_floorState[oldNum]) {
            newState[newNum] = _floorState[oldNum];
        }
    });

    _floorState = newState;
}

function uploadFloorImages(floorNum, inputEl) {
    const files = Array.from(inputEl.files || []);
    if (!files.length) return;

    // Client-side validation
    const maxSize = 25 * 1024 * 1024; // 25MB per floor photo
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

    const invalid = files.filter(f => !allowedTypes.includes(f.type));
    if (invalid.length > 0) {
        showFloorAlert(false, 'Invalid file type: ' + invalid.map(f => f.name).join(', ') + '. Only JPEG, PNG, GIF allowed.');
        inputEl.value = '';
        return;
    }

    const tooBig = files.filter(f => f.size > maxSize);
    if (tooBig.length > 0) {
        showFloorAlert(false, 'File(s) too large: ' + tooBig.map(f => f.name).join(', ') + '. Max 25MB each.');
        inputEl.value = '';
        return;
    }

    const propId = window._propertyId;
    const fd = new FormData();
    fd.append('property_id', String(propId));
    fd.append('floor_number', String(floorNum));
    files.forEach(f => fd.append('floor_images[]', f));

    showFloorAlert(true, 'Uploading Floor ' + floorNum + ' images...');

    fetch('upload_floor_image.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data?.success && Array.isArray(data.photos)) {
                const grid = document.getElementById('floorGrid_' + floorNum);
                if (grid) {
                    data.photos.forEach(photo => {
                        const item = document.createElement('div');
                        item.className = 'floor-photo-item';
                        item.setAttribute('data-url', photo.url);
                        item.setAttribute('data-floor', floorNum);
                        item.innerHTML = `
                            <img src="../${photo.url}" alt="Floor ${floorNum} Photo">
                            <div class="floor-photo-overlay">
                                <button type="button" class="edit-photo-btn btn-delete" title="Delete"
                                        onclick="deleteFloorImage(${floorNum}, '${photo.url.replace(/'/g, "\\'")}', this)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>`;
                        grid.appendChild(item);
                    });
                }
                updateFloorCount(floorNum);
                showFloorAlert(true, data.photos.length + ' floor image(s) uploaded.');
                inputEl.value = '';
            } else {
                showFloorAlert(false, data?.message || 'Upload failed.');
            }
        })
        .catch(() => showFloorAlert(false, 'Network error uploading floor images.'));
}

function deleteFloorImage(floorNum, photoUrl, btnEl) {
    const itemEl = btnEl.closest('.floor-photo-item');

    // Mark for deletion (will delete on save)
    if (!window.__modEditProp_deletedFloorImages) window.__modEditProp_deletedFloorImages = [];
    window.__modEditProp_deletedFloorImages.push({
        floor_number: floorNum,
        photo_url: photoUrl
    });

    if (itemEl) itemEl.remove();
    updateFloorCount(floorNum);
    showFloorAlert(true, 'Floor image marked for deletion. Save changes to confirm.');
}

// =====================================================================
// Update Price
// =====================================================================
(function () {
    const form = document.getElementById('updatePriceForm');
    const newPriceInput = document.getElementById('newPriceInput');
    const currentPrice = window._currentListingPrice || 0;
    const previewBox = document.getElementById('priceChangePreview');
    const alertBox = document.getElementById('priceUpdateAlert');

    function showPriceAlert(ok, msg) {
        alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');
        alertBox.classList.add(ok ? 'alert-success' : 'alert-danger');
        alertBox.innerHTML = '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i> ' + msg;
    }

    if (newPriceInput) {
        newPriceInput.addEventListener('input', function () {
            const newPrice = parseFloat(this.value) || 0;
            if (newPrice > 0 && newPrice !== currentPrice) {
                const diff = newPrice - currentPrice;
                const percent = ((diff / currentPrice) * 100).toFixed(2);
                const isIncrease = diff > 0;
                const color = isIncrease ? '#4ade80' : '#ef4444';
                const icon = isIncrease ? 'arrow-up' : 'arrow-down';
                const sign = isIncrease ? '+' : '';
                previewBox.style.display = 'block';
                previewBox.innerHTML = `<div class="price-preview ${isIncrease ? '' : 'decrease'}">
                    <div style="font-size:0.8rem;color:var(--gray-400);margin-bottom:4px;">Price Change</div>
                    <div style="font-size:1.25rem;font-weight:700;color:${color};display:flex;align-items:center;justify-content:center;gap:6px;">
                        <i class="bi bi-${icon}"></i> ${sign}₱${Math.abs(diff).toLocaleString('en-US', { minimumFractionDigits: 2 })} (${sign}${percent}%)
                    </div></div>`;
            } else { previewBox.style.display = 'none'; }
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(form);
            const newPrice = parseFloat(fd.get('new_price')) || 0;
            if (newPrice <= 0) { showPriceAlert(false, 'Enter a valid price.'); return; }
            if (newPrice === currentPrice) { showPriceAlert(false, 'New price is the same as current.'); return; }

            const submitBtn = form.querySelector('button[type="submit"]');
            const origHtml = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';

            fetch('update_price_process.php', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(Array.from(fd.entries()))
            })
                .then(async r => { const t = await r.text(); try { return JSON.parse(t); } catch (e) { throw e; } })
                .then(data => {
                    if (data?.success) { showPriceAlert(true, data.message || 'Price updated! Reloading...'); setTimeout(() => window.location.reload(), 1200); }
                    else { showPriceAlert(false, data?.message || 'Failed to update.'); }
                })
                .catch(() => showPriceAlert(false, 'Network error.'))
                .finally(() => { submitBtn.disabled = false; submitBtn.innerHTML = origHtml; });
        });
    }
})();

// =====================================================================
// Tour Requests (lazy load in modal)
// =====================================================================
(function () {
    const modal = document.getElementById('tourRequestsModal');
    const content = document.getElementById('tourRequestsContent');
    const propertyId = window._propertyId;

    if (modal) {
        modal.addEventListener('show.bs.modal', function () {
            fetch(`get_property_tour_requests.php?property_id=${propertyId}`)
                .then(r => r.json())
                .then(data => {
                    if (data?.success && data.requests?.length > 0) {
                        let html = '';
                        const statusColors = {
                            'Pending': 'badge-pending', 'Confirmed': 'badge-sale-pending',
                            'Completed': 'badge-live', 'Cancelled': '', 'Rejected': 'badge-rejected'
                        };
                        data.requests.forEach(req => {
                            const badgeClass = statusColors[req.request_status] || '';
                            const initials = (req.user_name || 'U').charAt(0).toUpperCase();
                            html += `
                            <div style="background:rgba(37,99,235,0.04);border:1px solid rgba(37,99,235,0.12);border-radius:6px;padding:20px;margin-bottom:12px;">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center gap-3">
                                        <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--gold-dark),var(--gold));display:flex;align-items:center;justify-content:center;color:var(--black);font-weight:700;font-size:1.1rem;">${initials}</div>
                                        <div>
                                            <div style="font-weight:700;color:var(--white);">${req.user_name || 'User'}</div>
                                            <div style="font-size:0.8rem;color:var(--gray-400);">${req.user_email || ''} ${req.user_phone ? '• ' + req.user_phone : ''}</div>
                                        </div>
                                    </div>
                                    <span class="agent-status-badge ${badgeClass}">${req.request_status}</span>
                                </div>
                                <div class="d-flex gap-4 flex-wrap" style="font-size:0.875rem;color:var(--gray-300);margin-top:12px;">
                                    <div><i class="bi bi-calendar3 me-1" style="color:var(--blue-light);"></i> ${req.preferred_date || 'Not set'}</div>
                                    <div><i class="bi bi-clock me-1" style="color:var(--blue-light);"></i> ${req.preferred_time || 'Not set'}</div>
                                </div>
                                ${req.message ? '<div style="margin-top:10px;padding:10px;background:rgba(0,0,0,0.2);border-radius:4px;font-size:0.85rem;color:var(--gray-400);"><i class="bi bi-chat-left-text me-1"></i> ' + req.message + '</div>' : ''}
                                <div style="margin-top:8px;font-size:0.75rem;color:var(--gray-500);"><i class="bi bi-info-circle me-1"></i> Requested on ${req.request_date || 'N/A'}</div>
                            </div>`;
                        });
                        content.innerHTML = html;
                    } else if (data?.success) {
                        content.innerHTML = '<div class="text-center py-5"><i class="bi bi-calendar-x" style="font-size:3rem;color:var(--gray-600);opacity:0.4;"></i><p style="color:var(--gray-500);margin-top:12px;">No tour requests for this property yet.</p></div>';
                    } else {
                        content.innerHTML = '<div class="flash-alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> ' + (data?.message || 'Failed to load.') + '</div>';
                    }
                })
                .catch(() => { content.innerHTML = '<div class="flash-alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> Network error.</div>'; });
        });
    }
})();

// =====================================================================
// Mark as Sold Modal handler
// =====================================================================
document.getElementById('markSoldModal')?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    if (button) {
        const propertyId = button.getAttribute('data-property-id');
        const propertyTitle = button.getAttribute('data-property-title');
        if (propertyId) document.getElementById('propertyId').value = propertyId;
        if (propertyTitle) document.getElementById('propertyTitle').textContent = propertyTitle;
    }
});
