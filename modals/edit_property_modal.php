<!-- Edit Property Modal -->
<div class="modal fade" id="editPropertyModal" tabindex="-1" aria-labelledby="editPropertyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered modal-fullscreen-lg-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPropertyModalLabel">
                    <i class="bi bi-pencil-square me-2"></i>Edit Property Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editPropertyForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="property_id" id="edit_property_id">
                <input type="hidden" name="action" value="edit_property">
                
                <div class="modal-body">
                    <!-- Basic Information Section -->
                    <div class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-house me-2"></i>Basic Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="edit_StreetAddress" class="form-label">Street Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_StreetAddress" name="StreetAddress" required>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label for="edit_City" class="form-label">City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_City" name="City" required>
                            </div>
                            <div class="col-12 col-sm-6 col-md-2">
                                <label for="edit_Province" class="form-label">Province <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_Province" name="Province" required>
                            </div>
                            <div class="col-12 col-sm-6 col-md-1">
                                <label for="edit_ZIP" class="form-label">ZIP <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_ZIP" name="ZIP" maxlength="4" pattern="\d{4}" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="edit_Barangay" class="form-label">Barangay <span class="text-optional">(Optional)</span></label>
                                <input type="text" class="form-control" id="edit_Barangay" name="Barangay" placeholder="e.g., Brgy. San Jose">
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label for="edit_PropertyType" class="form-label">Property Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_PropertyType" name="PropertyType" required>
                                    <option value="">Select Type</option>
                                    <?php
                                        $pt_result = $conn->query("SELECT type_name FROM property_types ORDER BY type_name ASC");
                                        if ($pt_result) {
                                            while ($pt = $pt_result->fetch_assoc()) {
                                                echo '<option value="' . htmlspecialchars($pt['type_name']) . '">' . htmlspecialchars($pt['type_name']) . '</option>';
                                            }
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label for="edit_Status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_Status" name="Status" required>
                                    <option value="For Sale">For Sale</option>
                                    <option value="For Rent">For Rent</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Property Details Section -->
                    <div class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-rulers me-2"></i>Property Details
                        </h6>
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <label for="edit_YearBuilt" class="form-label">Year Built <span class="text-optional">(Optional)</span></label>
                                <input type="number" class="form-control" id="edit_YearBuilt" name="YearBuilt" min="1800">
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="edit_Bedrooms" class="form-label">Bedrooms <span class="text-optional">(Optional)</span></label>
                                <input type="number" class="form-control" id="edit_Bedrooms" name="Bedrooms" min="0">
                            </div>
                            <div class="col-6 col-md-2">
                                <label for="edit_Bathrooms" class="form-label">Bathrooms <span class="text-optional">(Optional)</span></label>
                                <input type="number" class="form-control" id="edit_Bathrooms" name="Bathrooms" min="0" step="0.5">
                            </div>
                            <div class="col-6 col-md-2">
                                <label for="edit_ListingDate" class="form-label">Listing Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="edit_ListingDate" name="ListingDate" required>
                            </div>
                            <div class="col-12 col-sm-6 col-md-2">
                                <label for="edit_ListingPrice" class="form-label">Price <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_ListingPrice" name="ListingPrice" step="0.01" min="0" required>
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="edit_SquareFootage" class="form-label">Square Footage <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_SquareFootage" name="SquareFootage" min="1" required>
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="edit_LotSize" class="form-label">Lot Size (acres) <span class="text-optional">(Optional)</span></label>
                                <input type="number" class="form-control" id="edit_LotSize" name="LotSize" step="0.01" min="0">
                            </div>
                            <div class="col-12 col-md-3">
                                <label for="edit_ParkingType" class="form-label">Parking Type <span class="text-optional">(Optional)</span></label>
                                <input type="text" class="form-control" id="edit_ParkingType" name="ParkingType">
                            </div>
                        </div>
                    </div>

                    <!-- Rental Details (conditional) -->
                    <div class="edit-section" id="editRentalSection" style="display:none;">
                        <h6 class="edit-section-title">
                            <i class="bi bi-key me-2"></i>Rental Details
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="edit_SecurityDeposit" class="form-label">Security Deposit</label>
                                <input type="number" class="form-control" id="edit_SecurityDeposit" name="SecurityDeposit" step="0.01" min="0">
                            </div>
                            <div class="col-md-3">
                                <label for="edit_LeaseTermMonths" class="form-label">Lease Term</label>
                                <select class="form-select" id="edit_LeaseTermMonths" name="LeaseTermMonths">
                                    <option value="">Select</option>
                                    <option value="6">6 months</option>
                                    <option value="12">12 months</option>
                                    <option value="18">18 months</option>
                                    <option value="24">24 months</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="edit_Furnishing" class="form-label">Furnishing</label>
                                <select class="form-select" id="edit_Furnishing" name="Furnishing">
                                    <option value="">Select</option>
                                    <option value="Unfurnished">Unfurnished</option>
                                    <option value="Semi-Furnished">Semi-Furnished</option>
                                    <option value="Fully Furnished">Fully Furnished</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="edit_AvailableFrom" class="form-label">Available From</label>
                                <input type="date" class="form-control" id="edit_AvailableFrom" name="AvailableFrom">
                            </div>
                        </div>
                    </div>

                    <!-- MLS Information -->
                    <div class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-database me-2"></i>MLS Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_Source" class="form-label">Source <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_Source" name="Source" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_MLSNumber" class="form-label">MLS Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_MLSNumber" name="MLSNumber" required>
                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-file-text me-2"></i>Description
                        </h6>
                        <textarea class="form-control" id="edit_ListingDescription" name="ListingDescription" rows="4" required></textarea>
                    </div>

                    <!-- Amenities -->
                    <div class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-star me-2"></i>Amenities
                        </h6>
                        <div id="editAmenitiesGrid" class="amenities-grid"></div>
                    </div>

                    <!-- Featured Photos Management -->
                    <div class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-images me-2"></i>Featured Photos
                        </h6>
                        <div id="editFeaturedAlert" class="alert d-none mb-2" role="alert"></div>
                        <div class="upload-zone mb-3">
                            <label for="editFeaturedPhotosInput" class="upload-area">
                                <i class="bi bi-cloud-upload upload-icon-small"></i>
                                <span>Click to add new featured photos</span>
                                <small class="text-muted d-block mt-1">JPEG, PNG, GIF up to 5MB each &bull; Max 20 photos</small>
                            </label>
                            <input type="file" id="editFeaturedPhotosInput" class="d-none" accept="image/jpeg,image/png,image/gif" multiple>
                        </div>
                        <div id="editFeaturedPhotosGrid" class="photos-grid"></div>
                        <div id="editFeaturedPhotosPlaceholder" class="text-center text-muted py-4">
                            <i class="bi bi-image" style="font-size: 2rem;"></i>
                            <p class="mt-2 mb-0">No featured photos yet</p>
                        </div>
                    </div>

                    <!-- Floor Photos Management -->
                    <div class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-layers me-2"></i>Floor Photos
                        </h6>
                        <div id="editFloorAlert" class="alert d-none mb-2" role="alert"></div>
                        <div id="editFloorPhotosContainer">
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-building" style="font-size: 2rem;"></i>
                                <p class="mt-2 mb-0">No floor photos available</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.modal-header {
    background: linear-gradient(135deg, #161209 0%, #2a2318 100%);
    color: #fff;
    border-bottom: none;
}

.modal-header .modal-title {
    font-weight: 600;
    font-size: 1.125rem;
}

.modal-xl {
    max-width: 1140px;
}

.modal-body {
    max-height: calc(100vh - 210px);
    overflow-y: auto;
    overflow-x: hidden;
}

.edit-section {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 1rem;
}

.text-optional {
    color: #d97706;
    font-weight: 500;
    font-size: 0.8em;
}

.edit-section-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #161209;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    align-items: center;
}

.edit-section-title i {
    color: #bc9e42;
}

.form-label {
    font-weight: 500;
    font-size: 0.875rem;
    color: #333;
    margin-bottom: 0.375rem;
}

.form-control, .form-select {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 0.5rem 0.75rem;
    font-size: 0.9375rem;
}

.form-control:focus, .form-select:focus {
    border-color: #bc9e42;
    box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
}

.amenities-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.625rem;
}

.amenity-checkbox {
    background: #f9fafb;
    padding: 0.625rem 0.875rem;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.amenity-checkbox:hover {
    background: #fefcf3;
    border-color: #bc9e42;
}

.amenity-checkbox input[type="checkbox"] {
    margin-right: 0.5rem;
}

.amenity-checkbox input[type="checkbox"]:checked {
    accent-color: #bc9e42;
}

.amenity-checkbox label {
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    margin: 0;
}

.modal-footer {
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

.btn-primary {
    background: linear-gradient(135deg, #bc9e42, #a08636);
    border: none;
    font-weight: 600;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #a08636, #8b7430);
}

.btn-secondary {
    background: #6c757d;
    border: none;
    font-weight: 600;
}

.btn-secondary:hover {
    background: #5a6268;
}

.upload-zone {
    margin-bottom: 1rem;
}

.upload-area {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    border: 2px dashed #d1d5db;
    border-radius: 8px;
    background: #f9fafb;
    cursor: pointer;
    transition: all 0.2s ease;
    margin: 0;
}

.upload-area:hover {
    border-color: #bc9e42;
    background: #fefcf3;
}

.upload-icon-small {
    font-size: 1.5rem;
    color: #bc9e42;
    margin-bottom: 0.5rem;
}

.photos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 0.75rem;
    margin-top: 1rem;
}

.photo-item-edit {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    background: #f0f0f0;
    padding-bottom: 100%;
}

.photo-item-edit img {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.photo-actions {
    position: absolute;
    top: 0.25rem;
    right: 0.25rem;
    display: flex;
    gap: 0.25rem;
    z-index: 10;
}

.photo-btn {
    background: rgba(0, 0, 0, 0.7);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    padding: 0;
}

.photo-btn:hover {
    background: rgba(0, 0, 0, 0.9);
}

.photo-btn.delete:hover {
    background: #dc2626;
}

.photo-btn.replace:hover {
    background: #2563eb;
}

.floor-photos-section {
    border-top: 1px solid #e5e7eb;
    padding-top: 1rem;
    margin-top: 1rem;
}

.floor-photos-section:first-child {
    border-top: none;
    margin-top: 0;
    padding-top: 0;
}

.floor-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #161209;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.floor-upload-btn {
    background: #bc9e42;
    border: none;
    color: white;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.floor-upload-btn:hover {
    background: #a08636;
}

.photo-item-edit.pending-delete {
    opacity: 0.35;
    pointer-events: none;
    position: relative;
}
.photo-item-edit.pending-delete::after {
    content: 'Pending Delete';
    position: absolute;
    bottom: 4px; left: 50%;
    transform: translateX(-50%);
    background: #dc2626;
    color: #fff;
    font-size: 0.65rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    white-space: nowrap;
    z-index: 15;
}
.photo-item-edit.pending-replace {
    position: relative;
}
.photo-item-edit.pending-replace::after {
    content: 'Pending Replace';
    position: absolute;
    bottom: 4px; left: 50%;
    transform: translateX(-50%);
    background: #2563eb;
    color: #fff;
    font-size: 0.65rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    white-space: nowrap;
    z-index: 15;
}
.deferred-notice {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px; margin-bottom: 10px;
    border-radius: 6px; font-size: 0.8rem; font-weight: 500;
    background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
}
.deferred-notice i { font-size: 1rem; }
</style>

<script>
/* ══════════════════════════════════════════════════════════════
   Admin Edit Property Modal – JavaScript
   All delete/replace operations are DEFERRED until Save Changes.
   ══════════════════════════════════════════════════════════════ */

// ── Deferred change queues ──
let _adminPendingDeleteFeatured = [];   // [{id, url}]
let _adminPendingReplaceFeatured = [];  // [{id, oldUrl, file}]
let _adminPendingDeleteFloor = [];      // [{id, url, floor}]
let _adminPendingReplaceFloor = [];     // [{id, oldUrl, floor, file}]
let _adminNewFeaturedFiles = [];        // File objects for new uploads
let _adminNewFloorFiles = {};           // { floorNum: [File, ...] }
let _adminCurrentPropertyId = null;
let _adminOriginalPhotoCount = 0;

function _resetDeferredState() {
    _adminPendingDeleteFeatured = [];
    _adminPendingReplaceFeatured = [];
    _adminPendingDeleteFloor = [];
    _adminPendingReplaceFloor = [];
    _adminNewFeaturedFiles = [];
    _adminNewFloorFiles = {};
    _adminCurrentPropertyId = null;
    _adminOriginalPhotoCount = 0;
}

// ── Alert helpers ──
function showFeaturedAlert(ok, msg) {
    const el = document.getElementById('editFeaturedAlert');
    if (!el) return;
    el.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-info');
    el.classList.add(ok ? 'alert-success' : 'alert-danger');
    el.textContent = msg;
    setTimeout(() => el.classList.add('d-none'), 5000);
}
function showFloorAlert(ok, msg) {
    const el = document.getElementById('editFloorAlert');
    if (!el) return;
    el.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-info');
    el.classList.add(ok ? 'alert-success' : 'alert-danger');
    el.textContent = msg;
    setTimeout(() => el.classList.add('d-none'), 5000);
}
function showPhotoAlert(message, type) {
    const modalBody = document.querySelector('#editPropertyModal .modal-body');
    const existing = modalBody.querySelector('.photo-alert');
    if (existing) existing.remove();
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show photo-alert`;
    alert.style.marginBottom = '1rem';
    alert.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    modalBody.insertBefore(alert, modalBody.firstChild);
    setTimeout(() => { if (alert.parentNode) alert.remove(); }, 5000);
}

// ══════════════════════════════════════════════════════════════
// Open Modal
// ══════════════════════════════════════════════════════════════
function openEditPropertyModal(propertyId) {
    _resetDeferredState();
    _adminCurrentPropertyId = propertyId;

    const modal = new bootstrap.Modal(document.getElementById('editPropertyModal'));

    fetch(`get_property_data.php?id=${propertyId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                populateEditForm(data.property, data.amenities, data.selectedAmenities);
                loadPropertyPhotos(propertyId);
                modal.show();
            } else {
                showPhotoAlert('Error loading property data: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(() => showPhotoAlert('Failed to load property data', 'danger'));
}

// ══════════════════════════════════════════════════════════════
// Photo Loading & Rendering
// ══════════════════════════════════════════════════════════════
function loadPropertyPhotos(propertyId) {
    fetch(`get_property_photos.php?property_id=${propertyId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderFeaturedPhotos(data.featured_photos || [], propertyId);
                renderFloorPhotos(data.floor_photos || [], propertyId);
                _adminOriginalPhotoCount = (data.featured_photos || []).length;
            }
        })
        .catch(() => {});
}

function renderFeaturedPhotos(photos, propertyId) {
    const grid = document.getElementById('editFeaturedPhotosGrid');
    const placeholder = document.getElementById('editFeaturedPhotosPlaceholder');

    if (photos.length === 0) {
        grid.style.display = 'none';
        placeholder.style.display = 'block';
        return;
    }

    grid.style.display = 'grid';
    placeholder.style.display = 'none';
    grid.innerHTML = '';

    photos.forEach((photo, index) => {
        const item = document.createElement('div');
        item.className = 'photo-item-edit';
        item.dataset.photoId = photo.id;
        item.dataset.photoUrl = photo.url;
        // Safely escape url for onclick attributes
        const safeUrl = (photo.url || '').replace(/'/g, "\\'");
        item.innerHTML = `
            <img src="${photo.url}" alt="Featured ${index + 1}">
            <div class="photo-actions">
                <button type="button" class="photo-btn replace" onclick="deferReplaceFeatured(${photo.id}, '${safeUrl}', this)" title="Replace photo">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
                <button type="button" class="photo-btn delete" onclick="deferDeleteFeatured(${photo.id}, '${safeUrl}', this)" title="Delete photo">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        grid.appendChild(item);
    });
}

function renderFloorPhotos(floorPhotos, propertyId) {
    const container = document.getElementById('editFloorPhotosContainer');

    if (Object.keys(floorPhotos).length === 0) {
        container.innerHTML = `<div class="text-center text-muted py-4"><i class="bi bi-building" style="font-size: 2rem;"></i><p class="mt-2 mb-0">No floor photos available</p></div>`;
        return;
    }

    container.innerHTML = '';

    Object.keys(floorPhotos).sort((a, b) => parseInt(a) - parseInt(b)).forEach(floorNum => {
        const photos = floorPhotos[floorNum];
        const section = document.createElement('div');
        section.className = 'floor-photos-section';

        const titleDiv = document.createElement('div');
        titleDiv.className = 'floor-title';
        titleDiv.innerHTML = `<i class="bi bi-building"></i><span>Floor ${floorNum}</span>
            <button type="button" class="floor-upload-btn ms-auto" onclick="addFloorPhotoAdmin(${propertyId}, ${floorNum})"><i class="bi bi-plus-circle me-1"></i>Add Photos</button>`;
        section.appendChild(titleDiv);

        const grid = document.createElement('div');
        grid.className = 'photos-grid';
        grid.id = 'adminFloorGrid_' + floorNum;

        photos.forEach((photo, index) => {
            const safeUrl = (photo.url || '').replace(/'/g, "\\'");
            const item = document.createElement('div');
            item.className = 'photo-item-edit';
            item.dataset.photoId = photo.id;
            item.dataset.photoUrl = photo.url;
            item.innerHTML = `
                <img src="${photo.url}" alt="Floor ${floorNum} photo ${index + 1}">
                <div class="photo-actions">
                    <button type="button" class="photo-btn replace" onclick="deferReplaceFloor(${photo.id}, '${safeUrl}', ${floorNum}, this)" title="Replace"><i class="bi bi-arrow-repeat"></i></button>
                    <button type="button" class="photo-btn delete" onclick="deferDeleteFloor(${photo.id}, '${safeUrl}', ${floorNum}, this)" title="Delete"><i class="bi bi-trash"></i></button>
                </div>`;
            grid.appendChild(item);
        });

        section.appendChild(grid);
        container.appendChild(section);
    });
}

// ══════════════════════════════════════════════════════════════
// Deferred Featured Photo Actions
// ══════════════════════════════════════════════════════════════
function deferDeleteFeatured(photoId, photoUrl, btnEl) {
    const item = btnEl.closest('.photo-item-edit');
    if (!item) return;

    // Ensure at least 1 photo remains
    const grid = document.getElementById('editFeaturedPhotosGrid');
    const visibleItems = grid.querySelectorAll('.photo-item-edit:not(.pending-delete)');
    if (visibleItems.length <= 1) {
        showFeaturedAlert(false, 'Cannot delete the only photo. Property must have at least one photo.');
        return;
    }

    _adminPendingDeleteFeatured.push({ id: photoId, url: photoUrl });
    item.classList.add('pending-delete');
    showFeaturedAlert(true, 'Photo marked for deletion. Click "Save Changes" to confirm.');
}

function deferReplaceFeatured(photoId, photoUrl, btnEl) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/gif';
    input.onchange = (e) => {
        const file = e.target.files[0];
        if (!file) return;

        // Validate
        const maxSize = 25 * 1024 * 1024;
        if (!['image/jpeg', 'image/png', 'image/gif'].includes(file.type)) {
            showFeaturedAlert(false, 'Invalid file type. Only JPEG, PNG, GIF allowed.');
            return;
        }
        if (file.size > maxSize) {
            showFeaturedAlert(false, 'File exceeds 25MB limit.');
            return;
        }

        const item = btnEl.closest('.photo-item-edit');
        if (!item) return;

        // Remove any prior pending replace for this same photo
        _adminPendingReplaceFeatured = _adminPendingReplaceFeatured.filter(r => r.id !== photoId);

        _adminPendingReplaceFeatured.push({ id: photoId, oldUrl: photoUrl, file: file });

        // Show local preview
        const reader = new FileReader();
        reader.onload = (ev) => {
            const img = item.querySelector('img');
            if (img) img.src = ev.target.result;
        };
        reader.readAsDataURL(file);

        item.classList.remove('pending-delete');
        item.classList.add('pending-replace');
        showFeaturedAlert(true, 'Replacement queued. Click "Save Changes" to confirm.');
    };
    input.click();
}

// ══════════════════════════════════════════════════════════════
// Deferred Floor Photo Actions
// ══════════════════════════════════════════════════════════════
function deferDeleteFloor(photoId, photoUrl, floorNum, btnEl) {
    const item = btnEl.closest('.photo-item-edit');
    if (!item) return;
    _adminPendingDeleteFloor.push({ id: photoId, url: photoUrl, floor: floorNum });
    item.classList.add('pending-delete');
    showFloorAlert(true, 'Floor photo marked for deletion. Click "Save Changes" to confirm.');
}

function deferReplaceFloor(photoId, photoUrl, floorNum, btnEl) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/gif';
    input.onchange = (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const maxSize = 25 * 1024 * 1024;
        if (!['image/jpeg', 'image/png', 'image/gif'].includes(file.type)) {
            showFloorAlert(false, 'Invalid file type. Only JPEG, PNG, GIF allowed.');
            return;
        }
        if (file.size > maxSize) {
            showFloorAlert(false, 'File exceeds 25MB limit.');
            return;
        }

        const item = btnEl.closest('.photo-item-edit');
        if (!item) return;

        _adminPendingReplaceFloor = _adminPendingReplaceFloor.filter(r => r.id !== photoId);
        _adminPendingReplaceFloor.push({ id: photoId, oldUrl: photoUrl, floor: floorNum, file: file });

        const reader = new FileReader();
        reader.onload = (ev) => {
            const img = item.querySelector('img');
            if (img) img.src = ev.target.result;
        };
        reader.readAsDataURL(file);

        item.classList.remove('pending-delete');
        item.classList.add('pending-replace');
        showFloorAlert(true, 'Replacement queued. Click "Save Changes" to confirm.');
    };
    input.click();
}

// ══════════════════════════════════════════════════════════════
// New Photo Uploads (also deferred — staged client-side)
// ══════════════════════════════════════════════════════════════
function addFloorPhotoAdmin(propertyId, floorNum) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/gif';
    input.multiple = true;
    input.onchange = (e) => {
        const files = Array.from(e.target.files || []);
        if (!files.length) return;
        const maxSize = 25 * 1024 * 1024;
        const ok = files.filter(f => ['image/jpeg', 'image/png', 'image/gif'].includes(f.type) && f.size <= maxSize);
        if (ok.length !== files.length) {
            showFloorAlert(false, 'Some files skipped (invalid type or >25MB).');
        }
        if (!ok.length) return;

        if (!_adminNewFloorFiles[floorNum]) _adminNewFloorFiles[floorNum] = [];
        _adminNewFloorFiles[floorNum].push(...ok);

        // Show previews
        const grid = document.getElementById('adminFloorGrid_' + floorNum);
        if (grid) {
            ok.forEach(file => {
                const item = document.createElement('div');
                item.className = 'photo-item-edit pending-replace';
                const reader = new FileReader();
                reader.onload = (ev) => { item.innerHTML = `<img src="${ev.target.result}" alt="New floor photo">`; };
                reader.readAsDataURL(file);
                grid.appendChild(item);
            });
        }
        showFloorAlert(true, ok.length + ' floor photo(s) staged. Click "Save Changes" to upload.');
    };
    input.click();
}

// ══════════════════════════════════════════════════════════════
// Form Population
// ══════════════════════════════════════════════════════════════
function populateEditForm(property, allAmenities, selectedAmenities) {
    document.getElementById('edit_property_id').value = property.property_ID || '';
    document.getElementById('edit_StreetAddress').value = property.StreetAddress || '';
    document.getElementById('edit_City').value = property.City || '';
    document.getElementById('edit_Province').value = property.Province || '';
    document.getElementById('edit_ZIP').value = property.ZIP || '';
    document.getElementById('edit_Barangay').value = property.Barangay || '';
    document.getElementById('edit_PropertyType').value = property.PropertyType || '';
    document.getElementById('edit_Status').value = property.Status || '';

    document.getElementById('edit_YearBuilt').value = (property.YearBuilt != null) ? property.YearBuilt : '';
    document.getElementById('edit_Bedrooms').value = (property.Bedrooms != null) ? property.Bedrooms : '';
    document.getElementById('edit_Bathrooms').value = (property.Bathrooms != null) ? property.Bathrooms : '';

    // Listing date
    const ldInput = document.getElementById('edit_ListingDate');
    if (property.ListingDate && property.ListingDate !== '0000-00-00') {
        ldInput.value = property.ListingDate.split(' ')[0];
    } else {
        ldInput.value = new Date().toISOString().slice(0, 10);
    }

    document.getElementById('edit_ListingPrice').value = (property.ListingPrice != null) ? property.ListingPrice : '';
    document.getElementById('edit_SquareFootage').value = (property.SquareFootage != null) ? property.SquareFootage : '';
    document.getElementById('edit_LotSize').value = (property.LotSize != null) ? property.LotSize : '';
    document.getElementById('edit_ParkingType').value = property.ParkingType || '';

    // Rental details
    document.getElementById('edit_SecurityDeposit').value = property.SecurityDeposit || '';
    document.getElementById('edit_LeaseTermMonths').value = property.LeaseTermMonths || '';
    document.getElementById('edit_Furnishing').value = property.Furnishing || '';
    if (property.AvailableFrom && property.AvailableFrom !== '0000-00-00') {
        document.getElementById('edit_AvailableFrom').value = property.AvailableFrom.split(' ')[0];
    } else {
        document.getElementById('edit_AvailableFrom').value = '';
    }

    // MLS
    document.getElementById('edit_Source').value = property.Source || '';
    document.getElementById('edit_MLSNumber').value = property.MLSNumber || '';
    document.getElementById('edit_ListingDescription').value = property.ListingDescription || '';

    // Show/hide rental section
    toggleEditRentalSection();

    // Amenities
    const amenitiesGrid = document.getElementById('editAmenitiesGrid');
    amenitiesGrid.innerHTML = '';
    if (allAmenities && allAmenities.length > 0) {
        allAmenities.forEach(a => {
            const checked = selectedAmenities.includes(parseInt(a.amenity_id));
            const d = document.createElement('div');
            d.className = 'amenity-checkbox';
            d.innerHTML = `<input type="checkbox" name="amenities[]" value="${a.amenity_id}" id="edit_amenity_${a.amenity_id}" ${checked ? 'checked' : ''}>
                <label for="edit_amenity_${a.amenity_id}">${a.amenity_name}</label>`;
            amenitiesGrid.appendChild(d);
        });
    }
}

function toggleEditRentalSection() {
    const status = document.getElementById('edit_Status').value;
    document.getElementById('editRentalSection').style.display = (status === 'For Rent') ? 'block' : 'none';
}

// ══════════════════════════════════════════════════════════════
// Process All Deferred Photo Changes (called from form submit)
// ══════════════════════════════════════════════════════════════
async function processAllDeferredPhotoChanges(propertyId) {
    const results = [];

    // 1. Featured deletes
    for (const del of _adminPendingDeleteFeatured) {
        try {
            const r = await fetch('delete_featured_photo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ property_id: propertyId, photo_id: del.id, photo_url: del.url })
            });
            const d = await r.json();
            if (!d.success) results.push('Delete featured #' + del.id + ': ' + (d.message || 'failed'));
        } catch (e) { results.push('Delete featured #' + del.id + ': network error'); }
    }

    // 2. Featured replaces
    for (const rep of _adminPendingReplaceFeatured) {
        try {
            const fd = new FormData();
            fd.append('property_id', propertyId);
            fd.append('photo_id', rep.id);
            fd.append('old_url', rep.oldUrl);
            fd.append('image', rep.file);
            const r = await fetch('update_featured_photo.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d.success) results.push('Replace featured #' + rep.id + ': ' + (d.message || 'failed'));
        } catch (e) { results.push('Replace featured #' + rep.id + ': network error'); }
    }

    // 3. New featured uploads
    if (_adminNewFeaturedFiles.length > 0) {
        try {
            const fd = new FormData();
            fd.append('property_id', propertyId);
            _adminNewFeaturedFiles.forEach(f => fd.append('images[]', f));
            const r = await fetch('add_featured_photos.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d.success) results.push('New featured: ' + (d.message || 'failed'));
        } catch (e) { results.push('New featured: network error'); }
    }

    // 4. Floor deletes
    for (const del of _adminPendingDeleteFloor) {
        try {
            const r = await fetch('delete_floor_photo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ property_id: propertyId, floor_number: del.floor, photo_id: del.id, photo_url: del.url })
            });
            const d = await r.json();
            if (!d.success) results.push('Delete floor #' + del.id + ': ' + (d.message || 'failed'));
        } catch (e) { results.push('Delete floor #' + del.id + ': network error'); }
    }

    // 5. Floor replaces
    for (const rep of _adminPendingReplaceFloor) {
        try {
            const fd = new FormData();
            fd.append('property_id', propertyId);
            fd.append('floor_number', rep.floor);
            fd.append('photo_id', rep.id);
            fd.append('old_url', rep.oldUrl);
            fd.append('image', rep.file);
            const r = await fetch('update_floor_photo.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d.success) results.push('Replace floor #' + rep.id + ': ' + (d.message || 'failed'));
        } catch (e) { results.push('Replace floor #' + rep.id + ': network error'); }
    }

    // 6. New floor uploads
    for (const [floorNum, files] of Object.entries(_adminNewFloorFiles)) {
        if (!files.length) continue;
        try {
            const fd = new FormData();
            fd.append('property_id', propertyId);
            fd.append('floor_number', floorNum);
            files.forEach(f => fd.append('images[]', f));
            const r = await fetch('add_floor_photos.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d.success) results.push('New floor ' + floorNum + ': ' + (d.message || 'failed'));
        } catch (e) { results.push('New floor ' + floorNum + ': network error'); }
    }

    return results;
}

// ══════════════════════════════════════════════════════════════
// DOM Ready — Event Listeners
// ══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
    const editStatusSelect = document.getElementById('edit_Status');
    if (editStatusSelect) editStatusSelect.addEventListener('change', toggleEditRentalSection);

    // New featured photo file picker (staged client-side)
    const featuredInput = document.getElementById('editFeaturedPhotosInput');
    if (featuredInput) {
        featuredInput.addEventListener('change', function(e) {
            const files = Array.from(e.target.files || []);
            if (!files.length) return;
            const maxSize = 25 * 1024 * 1024;
            const allowed = ['image/jpeg', 'image/png', 'image/gif'];
            const ok = files.filter(f => allowed.includes(f.type) && f.size <= maxSize);
            if (ok.length !== files.length) showFeaturedAlert(false, 'Some files skipped (invalid type or >25MB).');
            if (!ok.length) { this.value = ''; return; }

            // Max 20 total check
            const grid = document.getElementById('editFeaturedPhotosGrid');
            const currentCount = grid.querySelectorAll('.photo-item-edit:not(.pending-delete)').length;
            if (currentCount + ok.length > 20) {
                showFeaturedAlert(false, 'Maximum 20 photos. Currently ' + currentCount + ' active.');
                this.value = '';
                return;
            }

            _adminNewFeaturedFiles.push(...ok);

            // Show local previews
            grid.style.display = 'grid';
            document.getElementById('editFeaturedPhotosPlaceholder').style.display = 'none';
            ok.forEach(file => {
                const item = document.createElement('div');
                item.className = 'photo-item-edit pending-replace';
                const reader = new FileReader();
                reader.onload = (ev) => { item.innerHTML = `<img src="${ev.target.result}" alt="New photo">`; };
                reader.readAsDataURL(file);
                grid.appendChild(item);
            });

            showFeaturedAlert(true, ok.length + ' photo(s) staged. Click "Save Changes" to upload.');
            this.value = '';
        });
    }

    // Form submission
    const editForm = document.getElementById('editPropertyForm');
    if (editForm) {
        editForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const submitBtn = this.querySelector('button[type="submit"]');
            const btnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            const propertyId = document.getElementById('edit_property_id').value;
            const formData = new FormData(this);

            try {
                // 1. Save text fields + amenities
                const res = await fetch('update_property.php', { method: 'POST', body: formData });
                if (!res.ok) throw new Error('Network error');
                const data = await res.json();

                if (!data.success) {
                    showPhotoAlert('Error: ' + (data.message || 'Failed to update property'), 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = btnText;
                    return;
                }

                // 2. Process all deferred photo changes
                const hasDeferredChanges = _adminPendingDeleteFeatured.length > 0
                    || _adminPendingReplaceFeatured.length > 0
                    || _adminNewFeaturedFiles.length > 0
                    || _adminPendingDeleteFloor.length > 0
                    || _adminPendingReplaceFloor.length > 0
                    || Object.values(_adminNewFloorFiles).some(f => f.length > 0);

                if (hasDeferredChanges) {
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing photos...';
                    const photoErrors = await processAllDeferredPhotoChanges(propertyId);
                    if (photoErrors.length > 0) {
                        showPhotoAlert('Property saved, but some photo operations had issues: ' + photoErrors.join('; '), 'warning');
                    }
                }

                showPhotoAlert('Property updated successfully! Reloading...', 'success');
                setTimeout(() => window.location.reload(), 1200);

            } catch (err) {
                showPhotoAlert('Failed to update property. Please try again.', 'danger');
                submitBtn.disabled = false;
                submitBtn.innerHTML = btnText;
            }
        });
    }
});
</script>
