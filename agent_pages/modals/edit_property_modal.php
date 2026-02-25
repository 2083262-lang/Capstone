<!-- ===================================================================
     Edit / Update Property Modal
     Included by: view_agent_property.php
     Variables expected: $property_id, $property_data, $property_images,
                         $floor_images, $all_amenities, $property_amenity_ids,
                         $rental_details
     =================================================================== -->
<div class="modal fade modal-dark" id="editPropertyModal" tabindex="-1" aria-labelledby="editPropertyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPropertyModalLabel"><i class="bi bi-pencil-square"></i> Update Property Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="updateAlert" class="flash-alert d-none" role="alert"></div>

                <form id="editPropertyForm" novalidate>
                    <input type="hidden" name="property_id" value="<?php echo (int)$property_id; ?>" />

                    <!-- ══════════════════════════════════════════════ -->
                    <!-- ROW 1 — Property Location (full width)        -->
                    <!-- ══════════════════════════════════════════════ -->
                    <div class="edit-form-card">
                        <div class="edit-section-header">
                            <i class="bi bi-geo-alt-fill"></i>
                            <div>
                                <h6>Property Location</h6>
                                <p>Address and location details</p>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Street Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="StreetAddress" value="<?php echo htmlspecialchars($property_data['StreetAddress']); ?>" placeholder="e.g. 123 Main Street" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="City" value="<?php echo htmlspecialchars($property_data['City']); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Barangay <span class="text-optional">(Optional)</span></label>
                                <input type="text" class="form-control" name="Barangay" value="<?php echo htmlspecialchars($property_data['Barangay'] ?? ''); ?>" placeholder="e.g., Brgy. San Jose">
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-2">
                                <label class="form-label">Province <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="Province" value="<?php echo htmlspecialchars($property_data['Province']); ?>" placeholder="e.g., Cebu" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">ZIP <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="ZIP" value="<?php echo htmlspecialchars($property_data['ZIP']); ?>" maxlength="4" pattern="\d{4}" inputmode="numeric" required>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════ -->
                    <!-- ROW 2 — Classification + Specifications (2 col) -->
                    <!-- ══════════════════════════════════════════════ -->
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="edit-form-card h-100">
                                <div class="edit-section-header">
                                    <i class="bi bi-tags-fill"></i>
                                    <div>
                                        <h6>Property Classification</h6>
                                        <p>Type, pricing &amp; listing information</p>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Property Type <span class="text-danger">*</span></label>
                                        <select class="form-select" name="PropertyType" required>
                                            <option disabled value="">Select Property Type</option>
                                            <?php
                                                $types = ['Single-Family Home','Condominium','Townhouse','Multi-Family','Land','Commercial'];
                                                $cur = trim((string)$property_data['PropertyType']);
                                                foreach ($types as $t) {
                                                    $sel = strcasecmp($cur,$t)===0 ? 'selected' : '';
                                                    echo '<option value="'.htmlspecialchars($t).'" '.$sel.'>'.htmlspecialchars($t).'</option>';
                                                }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Parking Type <span class="text-optional">(Optional)</span></label>
                                        <input type="text" class="form-control" name="ParkingType" value="<?php echo htmlspecialchars($property_data['ParkingType'] ?? ''); ?>" placeholder="e.g., Garage, Driveway">
                                    </div>
                                </div>
                                <div class="row g-3 mt-1">
                                    <div class="col-md-6">
                                        <label class="form-label">Listing Price <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text" style="background:rgba(212,175,55,0.1);border-color:rgba(255,255,255,0.15);color:var(--gold);font-weight:700;">₱</span>
                                            <input type="number" min="0" step="0.01" class="form-control" name="ListingPrice" value="<?php echo htmlspecialchars($property_data['ListingPrice']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Listing Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="ListingDate" value="<?php echo htmlspecialchars(date('Y-m-d', strtotime($property_data['ListingDate']))); ?>" max="<?php echo date('Y-m-d'); ?>" required style="color-scheme: dark;">
                                    </div>
                                </div>
                                <div class="row g-3 mt-1">
                                    <div class="col-md-6">
                                        <label class="form-label">MLS Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="MLSNumber" value="<?php echo htmlspecialchars($property_data['MLSNumber'] ?? ''); ?>" placeholder="e.g., MLS123456" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Source <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="Source" value="<?php echo htmlspecialchars($property_data['Source'] ?? ''); ?>" placeholder="e.g., Regional MLS" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="edit-form-card h-100">
                                <div class="edit-section-header">
                                    <i class="bi bi-rulers"></i>
                                    <div>
                                        <h6>Property Specifications</h6>
                                        <p>Physical attributes &amp; dimensions</p>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Bedrooms <span class="text-optional">(Optional)</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text edit-input-icon"><i class="bi bi-door-open"></i></span>
                                            <input type="number" min="0" class="form-control" name="Bedrooms" value="<?php echo (int)$property_data['Bedrooms']; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Bathrooms <span class="text-optional">(Optional)</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text edit-input-icon"><i class="bi bi-droplet"></i></span>
                                            <input type="number" min="0" step="0.5" class="form-control" name="Bathrooms" value="<?php echo htmlspecialchars($property_data['Bathrooms']); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Year Built <span class="text-optional">(Optional)</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text edit-input-icon"><i class="bi bi-calendar3"></i></span>
                                            <input type="number" min="1800" max="<?php echo date('Y'); ?>" class="form-control" name="YearBuilt" value="<?php echo (int)$property_data['YearBuilt']; ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-3 mt-1">
                                    <div class="col-md-6">
                                        <label class="form-label">Square Footage (ft²) <span class="text-danger">*</span></label>
                                        <input type="number" min="0" class="form-control" name="SquareFootage" value="<?php echo (int)$property_data['SquareFootage']; ?>" placeholder="e.g., 2500" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Lot Size (acres) <span class="text-optional">(Optional)</span></label>
                                        <input type="number" min="0" step="0.01" class="form-control" name="LotSize" value="<?php echo htmlspecialchars($property_data['LotSize'] ?? ''); ?>" placeholder="e.g., 0.25">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════ -->
                    <!-- ROW 3 — Description (full width)              -->
                    <!-- ══════════════════════════════════════════════ -->
                    <div class="edit-form-card">
                        <div class="edit-section-header">
                            <i class="bi bi-text-paragraph"></i>
                            <div>
                                <h6>Property Description</h6>
                                <p>Detailed listing description</p>
                            </div>
                        </div>
                        <textarea class="form-control" name="ListingDescription" rows="4" minlength="20" placeholder="Describe property features, highlights, and unique selling points..." required><?php echo htmlspecialchars($property_data['ListingDescription']); ?></textarea>
                        <div class="d-flex justify-content-between mt-2">
                            <small style="color: var(--gray-500);"><i class="bi bi-info-circle me-1"></i> Minimum 20 characters</small>
                            <small style="color: var(--gray-500);"><span id="charCount">0</span> / 1000</small>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════ -->
                    <!-- ROW 4 — Amenities (full width)                -->
                    <!-- ══════════════════════════════════════════════ -->
                    <div class="edit-form-card">
                        <div class="edit-section-header">
                            <i class="bi bi-check2-circle"></i>
                            <div>
                                <h6>Amenities &amp; Features</h6>
                                <p>Select all that apply to this property</p>
                            </div>
                        </div>
                        <div class="amenity-checkbox-grid">
                            <?php if (!empty($all_amenities)): ?>
                                <?php foreach ($all_amenities as $am): ?>
                                    <?php $checked = in_array((int)$am['amenity_id'], $property_amenity_ids) ? 'checked' : ''; ?>
                                    <label class="amenity-checkbox-item <?php echo $checked ? 'selected' : ''; ?>">
                                        <input type="checkbox" name="amenities[]" value="<?php echo (int)$am['amenity_id']; ?>" <?php echo $checked; ?>>
                                        <span class="amenity-checkbox-label"><?php echo htmlspecialchars($am['amenity_name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color: var(--gray-500); font-size: 0.85rem;">No amenities available.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════ -->
                    <!-- ROW 5 — Featured Photos (full width)          -->
                    <!-- ══════════════════════════════════════════════ -->
                    <div class="edit-form-card">
                        <div class="edit-section-header">
                            <i class="bi bi-images"></i>
                            <div>
                                <h6>Featured Photo Gallery</h6>
                                <p>Exterior, interior &amp; general property photos</p>
                            </div>
                        </div>
                        <label for="photoUploadInput" class="upload-area w-100 mb-3">
                            <i class="bi bi-cloud-arrow-up d-block"></i>
                            <div style="font-weight: 600; color: var(--white);">Click to upload photos</div>
                            <div style="font-size: 0.8rem; color: var(--gray-500);">JPEG, PNG, GIF &bull; Max 5MB each</div>
                        </label>
                        <input type="file" id="photoUploadInput" accept="image/*" multiple style="display: none;">
                        <div id="photosAlert" class="flash-alert d-none" role="alert"></div>
                        <div id="photosGrid" class="edit-photos-grid">
                            <?php foreach ($property_images as $idx => $img): ?>
                                <div class="edit-photo-item" data-url="<?php echo htmlspecialchars($img); ?>">
                                    <img src="../<?php echo htmlspecialchars($img); ?>" alt="Photo <?php echo $idx+1; ?>">
                                    <?php if ($idx === 0): ?>
                                        <div class="cover-badge-small"><i class="bi bi-star-fill"></i> Cover</div>
                                    <?php endif; ?>
                                    <div class="edit-photo-overlay">
                                        <button type="button" class="edit-photo-btn btn-move-left" title="Move left"><i class="bi bi-arrow-left"></i></button>
                                        <button type="button" class="edit-photo-btn btn-set-cover" title="Set as cover"><i class="bi bi-star"></i></button>
                                        <button type="button" class="edit-photo-btn btn-move-right" title="Move right"><i class="bi bi-arrow-right"></i></button>
                                        <button type="button" class="edit-photo-btn btn-delete btn-delete-photo" title="Delete"><i class="bi bi-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex justify-content-end mt-3">
                            <button type="button" id="savePhotoOrderBtn" class="btn btn-sm btn-dark-outline">
                                <i class="bi bi-save me-1"></i> Save Photo Order
                            </button>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════ -->
                    <!-- ROW 6 — Floor Plan Images (full width)        -->
                    <!-- ══════════════════════════════════════════════ -->
                    <div class="edit-form-card">
                        <div class="edit-section-header">
                            <i class="bi bi-building"></i>
                            <div>
                                <h6>Floor Plan Images</h6>
                                <p>Manage floor-specific photos (up to 10 floors)</p>
                            </div>
                        </div>
                        <div id="floorImagesAlert" class="flash-alert d-none" role="alert"></div>

                        <!-- Floor Tabs -->
                        <div class="floor-manager-tabs" id="floorManagerTabs">
                            <?php
                            $max_floor = !empty($floor_images) ? max(array_keys($floor_images)) : 0;
                            if ($max_floor > 0):
                                for ($f = 1; $f <= $max_floor; $f++):
                                    $count = isset($floor_images[$f]) ? count($floor_images[$f]) : 0;
                            ?>
                                <button type="button" class="floor-manager-tab <?php echo $f === 1 ? 'active' : ''; ?>"
                                        data-floor="<?php echo $f; ?>"
                                        onclick="switchFloorTab(<?php echo $f; ?>)">
                                    <i class="bi bi-layers"></i> Floor <?php echo $f; ?>
                                    <span class="floor-count" id="floorCount_<?php echo $f; ?>"><?php echo $count; ?></span>
                                </button>
                            <?php
                                endfor;
                            else:
                            ?>
                                <div class="floor-empty-state w-100">
                                    <i class="bi bi-building"></i>
                                    <div>No floor images yet. Click "Add Floor" to get started.</div>
                                </div>
                            <?php endif; ?>
                            <button type="button" class="add-floor-btn" id="addFloorBtn" onclick="addNewFloor()">
                                <i class="bi bi-plus-circle"></i> Add Floor
                            </button>
                        </div>

                        <!-- Floor Panels (one per floor) -->
                        <div id="floorPanelsContainer">
                            <?php if ($max_floor > 0): ?>
                                <?php for ($f = 1; $f <= $max_floor; $f++): ?>
                                <div class="floor-panel <?php echo $f === 1 ? 'active' : ''; ?>" id="floorPanel_<?php echo $f; ?>" data-floor="<?php echo $f; ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span style="font-size: 0.85rem; font-weight: 600; color: var(--white);">
                                            <i class="bi bi-layers me-1" style="color: var(--blue-light);"></i> Floor <?php echo $f; ?> Photos
                                        </span>
                                        <button type="button" class="remove-floor-btn" onclick="removeFloor(<?php echo $f; ?>)" title="Remove this floor and all its images">
                                            <i class="bi bi-trash me-1"></i> Remove Floor
                                        </button>
                                    </div>

                                    <div class="floor-photos-grid" id="floorGrid_<?php echo $f; ?>">
                                        <?php if (isset($floor_images[$f])): ?>
                                            <?php foreach ($floor_images[$f] as $fi_idx => $fi_url): ?>
                                                <div class="floor-photo-item" data-url="<?php echo htmlspecialchars($fi_url); ?>" data-floor="<?php echo $f; ?>">
                                                    <img src="../<?php echo htmlspecialchars($fi_url); ?>" alt="Floor <?php echo $f; ?> Photo <?php echo $fi_idx + 1; ?>">
                                                    <div class="floor-photo-overlay">
                                                        <button type="button" class="edit-photo-btn btn-delete" title="Delete" onclick="deleteFloorImage(<?php echo $f; ?>, '<?php echo htmlspecialchars(addslashes($fi_url)); ?>', this)">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                    <label class="floor-upload-area w-100" for="floorUpload_<?php echo $f; ?>">
                                        <i class="bi bi-cloud-arrow-up d-block"></i>
                                        <div style="font-weight: 600; color: var(--white); font-size: 0.85rem;">Upload Floor <?php echo $f; ?> Images</div>
                                        <div style="font-size: 0.75rem; color: var(--gray-500);">JPEG, PNG, GIF &bull; Max 10MB each</div>
                                    </label>
                                    <input type="file" id="floorUpload_<?php echo $f; ?>" accept="image/*" multiple style="display: none;"
                                           onchange="uploadFloorImages(<?php echo $f; ?>, this)">
                                </div>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Modal Footer Actions -->
                    <div class="modal-form-footer">
                        <div class="d-flex align-items-center gap-2" style="color: var(--gray-500); font-size: 0.8rem;">
                            <i class="bi bi-info-circle"></i>
                            <span>Fields marked <span class="text-danger">*</span> are required. Fields marked <span class="text-optional">(Optional)</span> can be left blank.</span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-dark-outline" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i> Cancel</button>
                            <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
