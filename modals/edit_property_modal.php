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
                                <label for="edit_State" class="form-label">State <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_State" name="State" maxlength="2" pattern="[A-Za-z]{2}" required>
                            </div>
                            <div class="col-12 col-sm-6 col-md-1">
                                <label for="edit_ZIP" class="form-label">ZIP <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_ZIP" name="ZIP" maxlength="4" pattern="\d{4}" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="edit_County" class="form-label">County <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_County" name="County" required>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label for="edit_PropertyType" class="form-label">Property Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_PropertyType" name="PropertyType" required>
                                    <option value="">Select Type</option>
                                    <option value="Single-Family Home">Single-Family Home</option>
                                    <option value="Condominium">Condominium</option>
                                    <option value="Townhouse">Townhouse</option>
                                    <option value="Multi-Family">Multi-Family</option>
                                    <option value="Land">Land</option>
                                    <option value="Commercial">Commercial</option>
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
                                <label for="edit_YearBuilt" class="form-label">Year Built <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_YearBuilt" name="YearBuilt" min="1800" required>
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="edit_Bedrooms" class="form-label">Bedrooms <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_Bedrooms" name="Bedrooms" min="0" required>
                            </div>
                            <div class="col-6 col-md-2">
                                <label for="edit_Bathrooms" class="form-label">Bathrooms <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_Bathrooms" name="Bathrooms" min="0" step="0.5" required>
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
                                <label for="edit_LotSize" class="form-label">Lot Size (acres) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_LotSize" name="LotSize" step="0.01" min="0" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label for="edit_ParkingType" class="form-label">Parking Type <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_ParkingType" name="ParkingType" required>
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
                        <div class="upload-zone mb-3">
                            <label for="editFeaturedPhotosInput" class="upload-area">
                                <i class="bi bi-cloud-upload upload-icon-small"></i>
                                <span>Click to add new featured photos</span>
                                <small class="text-muted d-block mt-1">JPEG, PNG, GIF up to 5MB each</small>
                            </label>
                            <input type="file" id="editFeaturedPhotosInput" class="d-none" accept="image/*" multiple>
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

</style>

<!-- Custom Confirmation Modal -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="confirmDeleteMessage" class="mb-0">Are you sure you want to delete this photo?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Custom confirmation dialog
let confirmDeleteCallback = null;

function showConfirmDelete(message, onConfirm) {
    document.getElementById('confirmDeleteMessage').textContent = message;
    confirmDeleteCallback = onConfirm;
    const modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (confirmDeleteCallback) {
                confirmDeleteCallback();
                confirmDeleteCallback = null;
            }
            bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal')).hide();
        });
    }
});

// Edit Property Modal Handler
function openEditPropertyModal(propertyId) {
    const modal = new bootstrap.Modal(document.getElementById('editPropertyModal'));
    
    // Fetch property data via AJAX
    fetch(`get_property_data.php?id=${propertyId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                populateEditForm(data.property, data.amenities, data.selectedAmenities);
                loadPropertyPhotos(propertyId);
                modal.show();
            } else {
                showPhotoAlert('Error loading property data: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showPhotoAlert('Failed to load property data', 'danger');
        });
}

function loadPropertyPhotos(propertyId) {
    // Load featured photos
    fetch(`get_property_photos.php?property_id=${propertyId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderFeaturedPhotos(data.featured_photos || [], propertyId);
                renderFloorPhotos(data.floor_photos || [], propertyId);
            }
        })
        .catch(err => console.error('Error loading photos:', err));
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
        item.innerHTML = `
            <img src="${photo.url}" alt="Featured photo ${index + 1}">
            <div class="photo-actions">
                <button class="photo-btn replace" onclick="replaceFeaturedPhoto(${propertyId}, '${photo.url}', ${photo.id})" title="Replace">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
                <button class="photo-btn delete" onclick="deleteFeaturedPhoto(${propertyId}, '${photo.url}', ${photo.id})" title="Delete">
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
        container.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-building" style="font-size: 2rem;"></i>
                <p class="mt-2 mb-0">No floor photos available</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = '';
    
    Object.keys(floorPhotos).sort((a, b) => parseInt(a) - parseInt(b)).forEach(floorNum => {
        const photos = floorPhotos[floorNum];
        const section = document.createElement('div');
        section.className = 'floor-photos-section';
        
        const titleDiv = document.createElement('div');
        titleDiv.className = 'floor-title';
        titleDiv.innerHTML = `
            <i class="bi bi-building"></i>
            <span>Floor ${floorNum}</span>
            <button class="floor-upload-btn ms-auto" onclick="addFloorPhoto(${propertyId}, ${floorNum})">
                <i class="bi bi-plus-circle me-1"></i>Add Photos
            </button>
        `;
        section.appendChild(titleDiv);
        
        const grid = document.createElement('div');
        grid.className = 'photos-grid';
        
        photos.forEach((photo, index) => {
            const item = document.createElement('div');
            item.className = 'photo-item-edit';
            item.innerHTML = `
                <img src="${photo.url}" alt="Floor ${floorNum} photo ${index + 1}">
                <div class="photo-actions">
                    <button class="photo-btn replace" onclick="replaceFloorPhoto(${propertyId}, ${floorNum}, '${photo.url}', ${photo.id})" title="Replace">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                    <button class="photo-btn delete" onclick="deleteFloorPhoto(${propertyId}, ${floorNum}, '${photo.url}', ${photo.id})" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
            grid.appendChild(item);
        });
        
        section.appendChild(grid);
        container.appendChild(section);
    });
}

// Featured photo management functions
function replaceFeaturedPhoto(propertyId, oldUrl, photoId) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = (e) => {
        const file = e.target.files[0];
        if (!file) return;
        
        const formData = new FormData();
        formData.append('property_id', propertyId);
        formData.append('photo_id', photoId);
        formData.append('old_url', oldUrl);
        formData.append('image', file);
        
        fetch('update_featured_photo.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadPropertyPhotos(propertyId);
                showPhotoAlert('Featured photo updated successfully', 'success');
            } else {
                showPhotoAlert(data.message || 'Failed to update photo', 'danger');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showPhotoAlert('Failed to update photo', 'danger');
        });
    };
    input.click();
}

function deleteFeaturedPhoto(propertyId, photoUrl, photoId) {
    showConfirmDelete('Are you sure you want to delete this photo?', function() {
        fetch('delete_featured_photo.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                property_id: propertyId,
                photo_id: photoId,
                photo_url: photoUrl
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadPropertyPhotos(propertyId);
                showPhotoAlert('Photo deleted successfully', 'success');
            } else {
                showPhotoAlert(data.message || 'Failed to delete photo', 'danger');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showPhotoAlert('Failed to delete photo', 'danger');
        });
    });
}

// Floor photo management functions
function addFloorPhoto(propertyId, floorNum) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.multiple = true;
    input.onchange = (e) => {
        const files = e.target.files;
        if (!files.length) return;
        
        const formData = new FormData();
        formData.append('property_id', propertyId);
        formData.append('floor_number', floorNum);
        for (let i = 0; i < files.length; i++) {
            formData.append('images[]', files[i]);
        }
        
        fetch('add_floor_photos.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadPropertyPhotos(propertyId);
                showPhotoAlert(`${data.count || files.length} photo(s) added successfully`, 'success');
            } else {
                showPhotoAlert(data.message || 'Failed to add photos', 'danger');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showPhotoAlert('Failed to add photos', 'danger');
        });
    };
    input.click();
}

function replaceFloorPhoto(propertyId, floorNum, oldUrl, photoId) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = (e) => {
        const file = e.target.files[0];
        if (!file) return;
        
        const formData = new FormData();
        formData.append('property_id', propertyId);
        formData.append('floor_number', floorNum);
        formData.append('photo_id', photoId);
        formData.append('old_url', oldUrl);
        formData.append('image', file);
        
        fetch('update_floor_photo.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadPropertyPhotos(propertyId);
                showPhotoAlert('Floor photo updated successfully', 'success');
            } else {
                showPhotoAlert(data.message || 'Failed to update photo', 'danger');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showPhotoAlert('Failed to update photo', 'danger');
        });
    };
    input.click();
}

function deleteFloorPhoto(propertyId, floorNum, photoUrl, photoId) {
    showConfirmDelete('Are you sure you want to delete this floor photo?', function() {
        fetch('delete_floor_photo.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                property_id: propertyId,
                floor_number: floorNum,
                photo_id: photoId,
                photo_url: photoUrl
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadPropertyPhotos(propertyId);
                showPhotoAlert('Floor photo deleted successfully', 'success');
            } else {
                showPhotoAlert(data.message || 'Failed to delete photo', 'danger');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showPhotoAlert('Failed to delete photo', 'danger');
        });
    });
}

function showPhotoAlert(message, type) {
    // Create alert element at top of modal body
    const modalBody = document.querySelector('#editPropertyModal .modal-body');
    const existingAlert = modalBody.querySelector('.photo-alert');
    if (existingAlert) existingAlert.remove();
    
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show photo-alert`;
    alert.style.marginBottom = '1rem';
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    modalBody.insertBefore(alert, modalBody.firstChild);
    
    setTimeout(() => {
        if (alert.parentNode) alert.remove();
    }, 5000);
}

function populateEditForm(property, allAmenities, selectedAmenities) {
    console.log('Property data:', property); // Debug log
    
    // Basic fields
    document.getElementById('edit_property_id').value = property.property_ID || '';
    document.getElementById('edit_StreetAddress').value = property.StreetAddress || '';
    document.getElementById('edit_City').value = property.City || '';
    document.getElementById('edit_State').value = property.State || '';
    document.getElementById('edit_ZIP').value = property.ZIP || '';
    document.getElementById('edit_County').value = property.County || '';
    document.getElementById('edit_PropertyType').value = property.PropertyType || '';
    document.getElementById('edit_Status').value = property.Status || '';
    
    // Property details - handle numbers properly
    document.getElementById('edit_YearBuilt').value = property.YearBuilt !== null && property.YearBuilt !== undefined ? property.YearBuilt : '';
    document.getElementById('edit_Bedrooms').value = property.Bedrooms !== null && property.Bedrooms !== undefined ? property.Bedrooms : '';
    document.getElementById('edit_Bathrooms').value = property.Bathrooms !== null && property.Bathrooms !== undefined ? property.Bathrooms : '';
    
    // Format listing date - MySQL returns as YYYY-MM-DD string
    console.log('ListingDate from DB:', property.ListingDate, typeof property.ListingDate);
    if (property.ListingDate && property.ListingDate !== '0000-00-00') {
        // If it's already in YYYY-MM-DD format, use it directly
        let dateStr = property.ListingDate;
        if (typeof dateStr === 'string' && dateStr.includes('-')) {
            // Extract just the date part if it's a datetime
            dateStr = dateStr.split(' ')[0];
            // Validate it's not 0000-00-00
            if (dateStr !== '0000-00-00') {
                document.getElementById('edit_ListingDate').value = dateStr;
                console.log('Set ListingDate to:', dateStr);
            } else {
                document.getElementById('edit_ListingDate').value = '';
                console.log('ListingDate is 0000-00-00, setting to empty');
            }
        } else {
            // Try to parse and format
            const listingDate = new Date(property.ListingDate);
            if (!isNaN(listingDate.getTime()) && listingDate.getFullYear() > 1000) {
                const year = listingDate.getFullYear();
                const month = String(listingDate.getMonth() + 1).padStart(2, '0');
                const day = String(listingDate.getDate()).padStart(2, '0');
                const formatted = `${year}-${month}-${day}`;
                document.getElementById('edit_ListingDate').value = formatted;
                console.log('Set ListingDate to (formatted):', formatted);
            } else {
                document.getElementById('edit_ListingDate').value = '';
                console.log('Invalid ListingDate, setting to empty');
            }
        }
    } else {
        document.getElementById('edit_ListingDate').value = '';
        console.log('ListingDate is null, empty, or 0000-00-00');
    }

    // Fallback: if ListingDate is still empty, use AvailableFrom if valid, else today's date
    (function() {
        const listingDateInput = document.getElementById('edit_ListingDate');
        if (!listingDateInput.value) {
            if (property.AvailableFrom && property.AvailableFrom !== '0000-00-00') {
                let af = property.AvailableFrom;
                if (typeof af === 'string') {
                    af = af.split(' ')[0];
                } else {
                    const afDate = new Date(property.AvailableFrom);
                    if (!isNaN(afDate.getTime())) {
                        const y = afDate.getFullYear();
                        const m = String(afDate.getMonth() + 1).padStart(2, '0');
                        const d = String(afDate.getDate()).padStart(2, '0');
                        af = `${y}-${m}-${d}`;
                    }
                }
                listingDateInput.value = af;
                console.log('Fallback ListingDate to AvailableFrom:', af);
            } else {
                const today = new Date();
                const t = today.toISOString().slice(0, 10);
                listingDateInput.value = t;
                console.log('Fallback ListingDate to today:', t);
            }
        }
    })();
    
    document.getElementById('edit_ListingPrice').value = property.ListingPrice !== null && property.ListingPrice !== undefined ? property.ListingPrice : '';
    document.getElementById('edit_SquareFootage').value = property.SquareFootage !== null && property.SquareFootage !== undefined ? property.SquareFootage : '';
    document.getElementById('edit_LotSize').value = property.LotSize !== null && property.LotSize !== undefined ? property.LotSize : '';
    document.getElementById('edit_ParkingType').value = property.ParkingType || '';
    
    // Rental details
    document.getElementById('edit_SecurityDeposit').value = property.SecurityDeposit || '';
    document.getElementById('edit_LeaseTermMonths').value = property.LeaseTermMonths || '';
    document.getElementById('edit_Furnishing').value = property.Furnishing || '';
    
    // Format available from date
    console.log('AvailableFrom from DB:', property.AvailableFrom, typeof property.AvailableFrom);
    if (property.AvailableFrom && property.AvailableFrom !== '0000-00-00') {
        let dateStr = property.AvailableFrom;
        if (typeof dateStr === 'string' && dateStr.includes('-')) {
            dateStr = dateStr.split(' ')[0];
            if (dateStr !== '0000-00-00') {
                document.getElementById('edit_AvailableFrom').value = dateStr;
                console.log('Set AvailableFrom to:', dateStr);
            } else {
                document.getElementById('edit_AvailableFrom').value = '';
                console.log('AvailableFrom is 0000-00-00, setting to empty');
            }
        } else {
            const availableDate = new Date(property.AvailableFrom);
            if (!isNaN(availableDate.getTime()) && availableDate.getFullYear() > 1000) {
                const year = availableDate.getFullYear();
                const month = String(availableDate.getMonth() + 1).padStart(2, '0');
                const day = String(availableDate.getDate()).padStart(2, '0');
                const formatted = `${year}-${month}-${day}`;
                document.getElementById('edit_AvailableFrom').value = formatted;
                console.log('Set AvailableFrom to (formatted):', formatted);
            } else {
                document.getElementById('edit_AvailableFrom').value = '';
                console.log('Invalid AvailableFrom, setting to empty');
            }
        }
    } else {
        document.getElementById('edit_AvailableFrom').value = '';
        console.log('AvailableFrom is null, empty, or 0000-00-00');
    }
    
    // MLS
    document.getElementById('edit_Source').value = property.Source || '';
    document.getElementById('edit_MLSNumber').value = property.MLSNumber || '';
    document.getElementById('edit_ListingDescription').value = property.ListingDescription || '';
    
    // Show/hide rental section
    toggleEditRentalSection();
    
    // Populate amenities
    const amenitiesGrid = document.getElementById('editAmenitiesGrid');
    amenitiesGrid.innerHTML = '';
    
    if (allAmenities && allAmenities.length > 0) {
        allAmenities.forEach(amenity => {
            const isChecked = selectedAmenities.includes(parseInt(amenity.amenity_id));
            const div = document.createElement('div');
            div.className = 'amenity-checkbox';
            div.innerHTML = `
                <input type="checkbox" 
                       name="amenities[]" 
                       value="${amenity.amenity_id}" 
                       id="edit_amenity_${amenity.amenity_id}"
                       ${isChecked ? 'checked' : ''}>
                <label for="edit_amenity_${amenity.amenity_id}">${amenity.amenity_name}</label>
            `;
            amenitiesGrid.appendChild(div);
        });
    }
}

function toggleEditRentalSection() {
    const status = document.getElementById('edit_Status').value;
    const rentalSection = document.getElementById('editRentalSection');
    if (status === 'For Rent') {
        rentalSection.style.display = 'block';
    } else {
        rentalSection.style.display = 'none';
    }
}

// Listen to status changes
document.addEventListener('DOMContentLoaded', function() {
    const editStatusSelect = document.getElementById('edit_Status');
    if (editStatusSelect) {
        editStatusSelect.addEventListener('change', toggleEditRentalSection);
    }
    
    // Handle featured photos upload
    const featuredPhotosInput = document.getElementById('editFeaturedPhotosInput');
    if (featuredPhotosInput) {
        featuredPhotosInput.addEventListener('change', function(e) {
            const files = e.target.files;
            if (!files.length) return;
            
            const propertyId = document.getElementById('edit_property_id').value;
            if (!propertyId) return;
            
            const formData = new FormData();
            formData.append('property_id', propertyId);
            for (let i = 0; i < files.length; i++) {
                formData.append('images[]', files[i]);
            }
            
            fetch('add_featured_photos.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadPropertyPhotos(propertyId);
                    showPhotoAlert(`${data.count || files.length} photo(s) added successfully`, 'success');
                    featuredPhotosInput.value = ''; // Reset input
                } else {
                    showPhotoAlert(data.message || 'Failed to add photos', 'danger');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showPhotoAlert('Failed to add photos', 'danger');
            });
        });
    }
    
    // Handle form submission
    const editForm = document.getElementById('editPropertyForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const btnText = submitBtn.innerHTML;
            
            // Disable button and show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
            
            const formData = new FormData(this);
            const propertyId = document.getElementById('edit_property_id').value;
            
            console.log('Submitting form for property ID:', propertyId);
            
            fetch(`update_property.php`, {
                method: 'POST',
                body: formData
            })
            .then(res => {
                console.log('Response status:', res.status);
                if (!res.ok) {
                    throw new Error('Network response was not ok');
                }
                return res.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    showPhotoAlert('Property updated successfully! Reloading...', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showPhotoAlert('Error: ' + (data.message || 'Failed to update property'), 'danger');
                    // Re-enable button
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = btnText;
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showPhotoAlert('Failed to update property. Please try again.', 'danger');
                // Re-enable button
                submitBtn.disabled = false;
                submitBtn.innerHTML = btnText;
            });
        });
    }
});
</script>
