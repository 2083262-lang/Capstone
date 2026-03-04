<?php
// Admin Property Card Template - Light Theme
// Matches agent/user page design language with light admin styling
// The $property variable is available from the parent file's loop.

// --- Data Preparation ---
$approval_status_class = strtolower(htmlspecialchars($property['approval_status'] ?? 'pending'));
$approval_status_text = htmlspecialchars(ucfirst(strtolower($property['approval_status'] ?? 'Pending')));

$is_sold = isset($property['Status']) && strtolower(trim($property['Status'])) === 'sold';
$is_pending_sold = isset($property['Status']) && strtolower(trim($property['Status'])) === 'pending sold';

$formatted_price = '₱' . number_format($property['ListingPrice']);
$is_for_rent = isset($property['Status']) && trim($property['Status']) === 'For Rent';
$rent_display = $is_for_rent ? ('₱' . number_format($property['rd_monthly_rent'] ?? $property['ListingPrice'])) : null;
$deposit_display = $is_for_rent && isset($property['rd_security_deposit']) ? ('₱' . number_format((float)$property['rd_security_deposit'])) : null;
$lease_display = $is_for_rent && isset($property['rd_lease_term_months']) ? ((int)$property['rd_lease_term_months'] . ' mo') : null;
$furnish_display = $is_for_rent ? htmlspecialchars($property['rd_furnishing'] ?? 'N/A') : null;
$avail_display = $is_for_rent && !empty($property['rd_available_from']) ? date("M d, Y", strtotime($property['rd_available_from'])) : null;

$full_location = htmlspecialchars(implode(', ', array_filter([$property['City'], $property['Province']])) . ' ' . $property['ZIP']);
$listing_date = date("M d, Y", strtotime($property['ListingDate']));

$listingDateObj = new DateTime($property['ListingDate']);
$today = new DateTime();
$interval = $today->diff($listingDateObj);
$days_on_market = $interval->days;

$poster_info = htmlspecialchars($property['poster_first_name'] ?? 'N/A') . ' (' . htmlspecialchars(ucfirst($property['poster_role_name'] ?? 'Unknown')) . ')';

// Override for sold statuses
if ($is_sold) {
    $approval_status_text = 'Sold';
    $approval_status_class = 'sold';
} elseif ($is_pending_sold) {
    $approval_status_text = 'Pending Sold';
    $approval_status_class = 'pending-sold';
}

// Badge determination
$badge_class = $approval_status_class;
$badge_icon = 'bi-clock-fill';
if ($is_sold) { $badge_icon = 'bi-check-all'; }
elseif ($is_pending_sold) { $badge_icon = 'bi-hourglass-split'; }
elseif ($approval_status_class === 'approved') { $badge_icon = 'bi-check-circle-fill'; }
elseif ($approval_status_class === 'rejected') { $badge_icon = 'bi-x-circle-fill'; }

// Image URL
$img_url = !empty($property['PhotoURL']) ? htmlspecialchars($property['PhotoURL']) : BASE_URL . 'images/placeholder.svg';

// Listing badge
$listing_status = $property['Status'] ?? 'For Sale';
$listing_class = 'for-sale';
$listing_icon = 'bi-tag-fill';
if (strtolower(trim($listing_status)) === 'sold' || strtolower(trim($listing_status)) === 'pending sold') {
    $listing_class = 'is-sold';
    $listing_icon = 'bi-check-circle-fill';
} elseif (trim($listing_status) === 'For Rent') {
    $listing_class = 'for-rent';
    $listing_icon = 'bi-key-fill';
}
?>

<div class="admin-prop-card">
    <!-- Image Section -->
    <div class="card-img-wrap">
        <img src="<?php echo $img_url; ?>" alt="<?php echo htmlspecialchars($property['StreetAddress']); ?>">
        <div class="img-overlay"></div>

        <!-- Listing Type Badge - Top Left -->
        <div class="listing-badge <?php echo $listing_class; ?>">
            <i class="bi <?php echo $listing_icon; ?>"></i>
            <?php echo htmlspecialchars($listing_status); ?>
        </div>

        <!-- Approval Status Badge - Top Right -->
        <div class="status-badge <?php echo $badge_class; ?>">
            <i class="bi <?php echo $badge_icon; ?>"></i>
            <?php echo $approval_status_text; ?>
        </div>

        <!-- Property Type - Bottom Left -->
        <div class="type-badge">
            <i class="bi bi-house-door"></i>
            <?php echo htmlspecialchars($property['PropertyType'] ?? 'Property'); ?>
        </div>

        <!-- Price Overlay - Bottom Right -->
        <div class="price-overlay">
            <span class="price"><?php echo $is_for_rent ? $rent_display : $formatted_price; ?></span>
            <?php if ($is_for_rent): ?>
                <span class="price-suffix"> / mo</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Card Body -->
    <div class="card-body-content">
        <!-- Address & Location -->
        <div class="prop-address" title="<?php echo htmlspecialchars($property['StreetAddress']); ?>">
            <?php echo htmlspecialchars($property['StreetAddress']); ?>
        </div>
        <div class="prop-location">
            <i class="bi bi-geo-alt-fill"></i>
            <?php echo $full_location; ?>
        </div>

        <!-- Property Stats -->
        <div class="stats-row">
            <div class="stat-item">
                <i class="bi bi-door-open-fill"></i>
                <strong><?php echo htmlspecialchars($property['Bedrooms'] ?? 0); ?></strong> Beds
            </div>
            <div class="stat-item">
                <i class="bi bi-droplet-fill"></i>
                <strong><?php echo htmlspecialchars($property['Bathrooms'] ?? 0); ?></strong> Baths
            </div>
            <div class="stat-item">
                <i class="bi bi-arrows-angle-expand"></i>
                <strong><?php echo number_format($property['SquareFootage'] ?? 0); ?></strong> sqft
            </div>
        </div>

        <!-- Meta Details -->
        <div class="meta-row">
            <div class="meta-item">
                <i class="bi bi-hash"></i> #<?php echo $property['property_ID']; ?>
            </div>
            <div class="meta-item">
                <i class="bi bi-eye-fill"></i> <?php echo number_format($property['ViewsCount'] ?? 0); ?>
            </div>
            <div class="meta-item">
                <i class="bi bi-calendar3"></i> <?php echo $listing_date; ?>
            </div>
            <div class="meta-item">
                <i class="bi bi-clock-history"></i> <?php echo $days_on_market; ?>d
            </div>
        </div>

        <!-- Rental Details (if For Rent) -->
        <?php if ($is_for_rent): ?>
        <div class="rental-info-section">
            <span class="rental-tag"><i class="bi bi-shield-fill-check"></i> Deposit: <strong><?php echo $deposit_display ?? '—'; ?></strong></span>
            <span class="rental-tag"><i class="bi bi-calendar-range"></i> Lease: <strong><?php echo $lease_display ?? '—'; ?></strong></span>
            <span class="rental-tag"><i class="bi bi-lamp-fill"></i> <strong><?php echo $furnish_display; ?></strong></span>
            <?php if ($avail_display): ?>
                <span class="rental-tag"><i class="bi bi-calendar-event"></i> Available: <strong><?php echo $avail_display; ?></strong></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="card-footer-section">
            <div class="posted-by">
                <i class="bi bi-person-circle"></i> <?php echo $poster_info; ?>
            </div>
            <a href="view_property.php?id=<?php echo $property['property_ID']; ?>" class="btn-manage">
                <i class="bi bi-sliders"></i> View & Manage
            </a>
        </div>
    </div>
</div>
