<?php
// Dark-themed property card template for agent_property.php
// The $property variable is available from the parent file's loop.

// --- Determine badge class and text ---
$badge_class = '';
$status_text = '';

if ($property['Status'] === 'Pending Sold') {
    $badge_class = 'pending-sold';
    $status_text = 'Pending Sold';
} elseif ($property['Status'] === 'Sold') {
    $badge_class = 'sold';
    $status_text = 'Sold';
} else {
    switch ($property['approval_status']) {
        case 'approved':
            $badge_class = 'live';
            $status_text = 'Live';
            break;
        case 'pending':
            $badge_class = 'pending';
            $status_text = 'Pending Review';
            break;
        case 'rejected':
            $badge_class = 'rejected';
            $status_text = 'Rejected';
            break;
    }
}

// --- Format data for display ---
$formatted_price = '₱' . number_format($property['ListingPrice']);
$is_for_rent = isset($property['Status']) && trim($property['Status']) === 'For Rent';
$rent_display = $is_for_rent ? ('₱' . number_format($property['rd_monthly_rent'] ?? $property['ListingPrice'])) : null;
$deposit_display = $is_for_rent && isset($property['rd_security_deposit']) ? ('₱' . number_format((float)$property['rd_security_deposit'])) : null;
$lease_display = $is_for_rent && isset($property['rd_lease_term_months']) ? ((int)$property['rd_lease_term_months'] . ' mo') : null;
$furnish_display = $is_for_rent ? htmlspecialchars($property['rd_furnishing'] ?? 'N/A') : null;
$avail_display = $is_for_rent && !empty($property['rd_available_from']) ? date("M d, Y", strtotime($property['rd_available_from'])) : null;

// --- Calculate Days On Market ---
$listingDateObj = new DateTime($property['ListingDate']);
$today = new DateTime();
$interval = $today->diff($listingDateObj);
$days_on_market = $interval->days;

// --- Image URL ---
$img_url = !empty($property['PhotoURL']) ? '../' . htmlspecialchars($property['PhotoURL']) : BASE_URL . 'images/placeholder.svg';

// --- Stats ---
$views = (int)($property['ViewsCount'] ?? 0);
$likes = (int)($property['Likes'] ?? 0);
$tours = (int)($property['tour_count'] ?? 0);
$photos = (int)($property['photo_count'] ?? 0);
?>

<div class="col-lg-4 col-md-6 mb-4"
     data-property-type="<?php echo htmlspecialchars($property['PropertyType'] ?? ''); ?>"
     data-status="<?php echo htmlspecialchars($property['Status'] ?? ''); ?>"
     data-city="<?php echo htmlspecialchars($property['City'] ?? ''); ?>"
     data-price="<?php echo $property['ListingPrice']; ?>"
     data-bedrooms="<?php echo (int)($property['Bedrooms'] ?? 0); ?>"
     data-bathrooms="<?php echo (float)($property['Bathrooms'] ?? 0); ?>"
     data-listing-date="<?php echo htmlspecialchars($property['ListingDate'] ?? ''); ?>"
     data-views="<?php echo $views; ?>"
     data-likes="<?php echo $likes; ?>">
    <div class="prop-card">
        <!-- Image Section -->
        <div class="prop-card-img-wrap">
            <img src="<?php echo $img_url; ?>" alt="<?php echo htmlspecialchars($property['StreetAddress']); ?>">
            <div class="overlay-gradient"></div>
            
            <!-- Status Badge -->
            <span class="prop-badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span>
            
            <!-- Property Type Badge -->
            <span class="prop-type-badge">
                <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($property['PropertyType'] ?? 'Property'); ?>
            </span>
            
            <!-- Price Overlay -->
            <div class="prop-price-overlay">
                <span class="price"><?php echo $is_for_rent ? $rent_display : $formatted_price; ?></span>
                <?php if ($is_for_rent): ?>
                    <span class="price-suffix"> / month</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card Body -->
        <div class="prop-card-body">
            <!-- Address & Location -->
            <div class="prop-address" title="<?php echo htmlspecialchars($property['StreetAddress']); ?>">
                <?php echo htmlspecialchars($property['StreetAddress']); ?>
            </div>
            <div class="prop-location">
                <i class="bi bi-geo-alt-fill"></i>
                <?php echo htmlspecialchars($property['City']); ?>, <?php echo htmlspecialchars($property['Province']); ?> <?php echo htmlspecialchars($property['ZIP']); ?>
            </div>

            <!-- Property Details Row -->
            <div class="prop-details-row">
                <div class="detail-item">
                    <i class="bi bi-door-open-fill"></i>
                    <strong><?php echo htmlspecialchars($property['Bedrooms'] ?? 0); ?></strong> Beds
                </div>
                <div class="detail-item">
                    <i class="bi bi-droplet-fill"></i>
                    <strong><?php echo htmlspecialchars($property['Bathrooms'] ?? 0); ?></strong> Baths
                </div>
                <div class="detail-item">
                    <i class="bi bi-arrows-angle-expand"></i>
                    <strong><?php echo number_format($property['SquareFootage'] ?? 0); ?></strong> sqft
                </div>
            </div>

            <!-- Performance Stats -->
            <div class="prop-stats-row">
                <span><i class="bi bi-eye-fill"></i> <?php echo number_format($views); ?> views</span>
                <span><i class="bi bi-heart-fill"></i> <?php echo number_format($likes); ?> likes</span>
                <span><i class="bi bi-calendar3"></i> <?php echo $days_on_market; ?>d on market</span>
                <?php if ($tours > 0): ?>
                    <span><i class="bi bi-people-fill"></i> <?php echo $tours; ?> tours</span>
                <?php endif; ?>
            </div>

            <!-- Rental Details (if applicable) -->
            <?php if ($is_for_rent): ?>
                <div class="rental-info">
                    <span class="rental-tag"><i class="bi bi-shield-fill-check"></i> Deposit: <strong><?php echo $deposit_display ?? '—'; ?></strong></span>
                    <span class="rental-tag"><i class="bi bi-calendar-range"></i> Lease: <strong><?php echo $lease_display ?? '—'; ?></strong></span>
                    <span class="rental-tag"><i class="bi bi-lamp-fill"></i> <strong><?php echo $furnish_display; ?></strong></span>
                    <?php if ($avail_display): ?>
                        <span class="rental-tag"><i class="bi bi-calendar-event"></i> Available: <strong><?php echo $avail_display; ?></strong></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Footer Actions -->
            <div class="prop-card-footer">
                <a href="view_agent_property.php?id=<?php echo $property['property_ID']; ?>" class="btn-view">
                    <i class="bi bi-eye"></i> View Details
                </a>
                <?php if ($property['approval_status'] === 'approved' && $property['Status'] != 'Pending Sold' && $property['Status'] != 'Sold'): ?>
                    <button type="button" class="btn-sold" data-bs-toggle="modal" data-bs-target="#markSoldModal" 
                            data-property-id="<?php echo $property['property_ID']; ?>" 
                            data-property-title="<?php echo htmlspecialchars($property['StreetAddress']); ?>">
                        <i class="bi bi-check-circle-fill"></i> Mark Sold
                    </button>
                <?php elseif ($property['Status'] === 'Pending Sold'): ?>
                    <div class="sale-badge verifying">
                        <i class="bi bi-hourglass-split"></i> Verifying Sale
                    </div>
                <?php elseif ($property['Status'] === 'Sold'): ?>
                    <div class="sale-badge completed">
                        <i class="bi bi-check-all"></i> Sale Completed
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
