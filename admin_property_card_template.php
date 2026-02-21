<?php
// This is a template file included in property.php (Admin side)
// The $property variable is available from the parent file's loop.

// --- Data Preparation ---

// 1. Approval Status Badge
// Normalize approval/status fields (support different DB column names/casing)
$status_raw = strtolower($property['approval_status'] ?? $property['Status'] ?? $property['status'] ?? 'pending');
$approval_status_class = strtolower(htmlspecialchars($property['approval_status'] ?? 'pending'));
$approval_status_text = htmlspecialchars(ucfirst(strtolower($property['approval_status'] ?? 'Pending')));

// Determine if this listing is sold from the Status field (For Sale/For Rent/Sold)
$is_sold = isset($property['Status']) && strtolower(trim($property['Status'])) === 'sold';
$is_pending_sold = isset($property['Status']) && strtolower(trim($property['Status'])) === 'pending sold';

// 2. Listing Status Badge
$status_class = '';
$status_icon = '';
switch ($property['Status']) {
    case 'For Sale': 
        $status_class = 'status-sale'; 
        $status_icon = 'bi-tag';
        break;
    case 'For Rent': 
        $status_class = 'status-rent'; 
        $status_icon = 'bi-key';
        break;
    case 'Sold': 
        $status_class = 'status-sold'; 
        $status_icon = 'bi-check-circle';
        break;
    default: 
        $status_class = 'status-other';
        $status_icon = 'bi-info-circle';
}

// 3. Format Data for Display
$formatted_price = '₱' . number_format($property['ListingPrice']);
// Rental-specific formatted values if For Rent
$is_for_rent = isset($property['Status']) && trim($property['Status']) === 'For Rent';
$rent_display = $is_for_rent ? ('₱' . number_format($property['rd_monthly_rent'] ?? $property['ListingPrice'])) : null;
$deposit_display = $is_for_rent && isset($property['rd_security_deposit']) ? ('₱' . number_format((float)$property['rd_security_deposit'])) : null;
$lease_display = $is_for_rent && isset($property['rd_lease_term_months']) ? ((int)$property['rd_lease_term_months'] . ' mo') : null;
$furnish_display = $is_for_rent ? htmlspecialchars($property['rd_furnishing'] ?? 'N/A') : null;
$avail_display = $is_for_rent && !empty($property['rd_available_from']) ? date("M d, Y", strtotime($property['rd_available_from'])) : null;
$full_location = htmlspecialchars(implode(', ', array_filter([$property['City'], $property['State']])) . ' ' . $property['ZIP']);
$listing_date = date("M d, Y", strtotime($property['ListingDate']));

// --- Calculate Days On Market in real-time ---
$listingDateObj = new DateTime($property['ListingDate']);
$today = new DateTime();
$interval = $today->diff($listingDateObj);
$days_on_market = $interval->days;

// --- Use the data from the property_log JOIN ---
$poster_info = htmlspecialchars($property['poster_first_name'] ?? 'N/A') . ' (' . htmlspecialchars(ucfirst($property['poster_role_name'] ?? 'Unknown')) . ')';

// Override approval status text based on the property's actual status
// This makes the badge reflect WHERE the property is shown (its true state)
$approval_status_text = htmlspecialchars(ucfirst($property['approval_status'] ?? 'Pending'));
if ($is_sold) {
    $approval_status_text = ucfirst(strtolower('Sold'));
    $approval_status_class = 'sold'; // Use a distinct class for styling
} elseif ($is_pending_sold) {
    $approval_status_text = ucfirst(strtolower('Pending sold'));
    $approval_status_class = 'pending'; // Keep pending styling (yellow)
}

?>

<style>
/* Property Card Specific Styles - Tailwind-inspired */
.property-card {
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
    height: 100%;
    position: relative;
    display: flex;
    flex-direction: column;
}

.property-card:hover {
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    border-color: #bc9e42;
}

.property-image-container {
    position: relative;
    height: 240px;
    overflow: hidden;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
}

.property-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Primary Status Badge - Top Left (For Sale / For Rent / Sold) */
.listing-type-badge {
    position: absolute;
    top: 1rem;
    left: 1rem;
    min-width: 120px;
    height: 36px;
    padding: 0 1.125rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 700;
    /* Keep natural casing; text will be normalized server-side to only capitalize first letter */
    letter-spacing: 0.02em;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-align: center;
    line-height: 1;
    z-index: 3;
}

.listing-type-badge.for-sale {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    border: 2px solid rgba(147, 197, 253, 0.5);
}

.listing-type-badge.for-rent {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #ffffff;
    border: 2px solid rgba(251, 191, 36, 0.5);
}

.listing-type-badge.sold {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    color: #ffffff;
    border: 2px solid rgba(156, 163, 175, 0.5);
}

.listing-type-badge i {
    font-size: 1rem;
    flex-shrink: 0;
}

/* Approval Status Badge - Top Right */
.approval-badge {
    position: absolute;
    top: 1rem;
    right: 1rem;
    min-width: 120px;
    height: 36px;
    padding: 0 1.125rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 700;
    /* Keep natural casing; approval text is normalized server-side */
    letter-spacing: 0.02em;
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    z-index: 3;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-align: center;
    line-height: 1;
}

.approval-badge i {
    font-size: 1rem;
    flex-shrink: 0;
}

.badge-pending {
    background: rgba(251, 191, 36, 0.95);
    color: #78350f;
    border: 2px solid rgba(251, 191, 36, 0.8);
}

.badge-approved {
    /* Slightly darker green for improved contrast */
    background: linear-gradient(135deg, #16a34a 0%, #059669 100%); /* #16a34a -> #059669 */
    color: #ffffff;
    border: 2px solid rgba(5, 150, 105, 0.85);
}

.badge-rejected {
    background: rgba(239, 68, 68, 0.95);
    color: #7f1d1d;
    border: 2px solid rgba(239, 68, 68, 0.8);
}

.badge-sold {
    background: rgba(107, 114, 128, 0.95);
    color: #1f2937;
    border: 2px solid rgba(107, 114, 128, 0.8);
}

/* Property Type Badge - Bottom Left */
.property-type-badge {
    position: absolute;
    bottom: 1rem;
    left: 1rem;
    background: rgba(0, 0, 0, 0.85);
    color: #ffffff;
    height: 32px;
    padding: 0 1rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    text-align: center;
    line-height: 1;
    z-index: 2;
    border: 2px solid rgba(255, 255, 255, 0.1);
}

.property-type-badge i {
    font-size: 0.875rem;
    flex-shrink: 0;
}

/* Views Count Badge - Bottom Right */
.views-count {
    position: absolute;
    bottom: 1rem;
    right: 1rem;
    background: rgba(0, 0, 0, 0.85);
    color: #ffffff;
    height: 32px;
    padding: 0 1rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    text-align: center;
    line-height: 1;
    z-index: 2;
    border: 2px solid rgba(255, 255, 255, 0.1);
}

.views-count i {
    font-size: 0.875rem;
    flex-shrink: 0;
}

.property-content {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    flex: 1;
    background: #ffffff;
}

.property-header {
    margin-bottom: 1rem;
}

.property-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 0.5rem;
    line-height: 1.375;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.75rem;
}

.property-location {
    color: #6b7280;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-weight: 500;
}

.property-location i {
    color: #9ca3af;
    font-size: 0.875rem;
}

.property-price {
    font-size: 1.875rem;
    font-weight: 800;
    color: #bc9e42;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
}

.price-period {
    font-size: 0.875rem;
    font-weight: 600;
    color: #6b7280;
}

/* Property Stats Grid */
.property-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    padding-bottom: 1.25rem;
    border-bottom: 2px solid #f3f4f6;
}

.stat-item {
    text-align: center;
    padding: 0.75rem 0.5rem;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.stat-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: #111827;
    display: block;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.6875rem;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Property Details */
.property-details {
    flex: 1;
    margin-bottom: 1rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.625rem 0;
    border-bottom: 1px solid #f3f4f6;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: #6b7280;
    font-size: 0.8125rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.375rem;
}

.detail-label i {
    color: #9ca3af;
    font-size: 0.875rem;
}

.detail-value {
    font-weight: 700;
    color: #111827;
    font-size: 0.875rem;
}

/* Rental Details Compact - Organized Grid Layout */
.rental-details-compact {
    background: linear-gradient(135deg, #fff9f0 0%, #fff5e6 100%);
    border: 2px solid #f59e0b;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1rem;
}

.rental-details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.875rem;
}

.rental-detail-item {
    background: #ffffff;
    border-radius: 8px;
    padding: 0.75rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 0.375rem;
    border: 1px solid rgba(245, 158, 11, 0.2);
    transition: all 0.2s ease;
}

.rental-detail-item:hover {
    border-color: #f59e0b;
    background: #fffbf5;
    box-shadow: 0 2px 4px rgba(245, 158, 11, 0.1);
}

.rental-detail-label {
    font-size: 0.6875rem;
    color: #92400e;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    display: flex;
    align-items: center;
    gap: 0.375rem;
    width: 100%;
    justify-content: center;
}

.rental-detail-label i {
    color: #f59e0b;
    font-size: 0.875rem;
}

.rental-detail-value {
    font-size: 0.9375rem;
    font-weight: 800;
    color: #78350f;
    line-height: 1.2;
}

.property-footer {
    margin-top: auto;
    padding-top: 1rem;
    border-top: 2px solid #f3f4f6;
}

.posted-by {
    font-size: 0.8125rem;
    color: #6b7280;
    margin-bottom: 1rem;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    font-weight: 500;
}

.posted-by i {
    color: #9ca3af;
}

.action-button {
    background: linear-gradient(135deg, #bc9e42 0%, #a08636 100%);
    color: #ffffff;
    border: none;
    padding: 0.875rem 1.5rem;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    box-shadow: 0 4px 6px -1px rgba(188, 158, 66, 0.3), 0 2px 4px -1px rgba(188, 158, 66, 0.2);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.action-button:hover {
    box-shadow: 0 10px 15px -3px rgba(188, 158, 66, 0.4), 0 4px 6px -2px rgba(188, 158, 66, 0.3);
    background: linear-gradient(135deg, #a08636 0%, #8a7330 100%);
    color: #ffffff;
}

.views-count {
    position: absolute;
    bottom: 1rem;
    right: 1rem;
    background: rgba(0, 0, 0, 0.85);
    color: #ffffff;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    gap: 0.375rem;
    z-index: 2;
}

.views-count i {
    font-size: 0.875rem;
}
</style>

<div class="property-card">
    <!-- Image Container -->
    <div class="property-image-container">
        <img src="<?php echo htmlspecialchars($property['PhotoURL'] ?? 'https://via.placeholder.com/400x280/f0f0f0/888888?text=No+Image'); ?>" 
             class="property-image" alt="Property Image">
        
        <!-- Primary Status Badge - For Sale / For Rent -->
        <?php
            $listing_status = $property['Status'] ?? 'For Sale';
            $listing_badge_class = '';
            $listing_icon = '';
            
            if (strtolower(trim($listing_status)) === 'sold') {
                $listing_badge_class = 'sold';
                $listing_icon = 'bi-check-circle-fill';
            } elseif (trim($listing_status) === 'For Rent') {
                $listing_badge_class = 'for-rent';
                $listing_icon = 'bi-key-fill';
            } else {
                $listing_badge_class = 'for-sale';
                $listing_icon = 'bi-tag-fill';
            }
        ?>
        <div class="listing-type-badge <?php echo $listing_badge_class; ?>">
            <i class="bi <?php echo $listing_icon; ?>"></i>
            <?php echo htmlspecialchars(ucfirst(strtolower($listing_status))); ?>
        </div>
        
        <!-- Approval Status Badge (Only show if NOT sold - to avoid redundancy) -->
        <?php if (!$is_sold && !$is_pending_sold): ?>
        <div class="approval-badge badge-<?php echo $approval_status_class; ?>">
            <i class="bi bi-<?php 
                if ($approval_status_class === 'approved') {
                    echo 'check-circle-fill';
                } elseif ($approval_status_class === 'rejected') {
                    echo 'x-circle-fill';
                } else {
                    echo 'clock-fill';
                }
            ?>"></i>
            <?php echo htmlspecialchars($approval_status_text); ?>
        </div>
        <?php endif; ?>
        
        <!-- Property Type Badge - Bottom Left -->
        <div class="property-type-badge">
            <i class="bi bi-house-door"></i>
            <?php echo htmlspecialchars(ucfirst(strtolower($property['PropertyType'] ?? ''))); ?>
        </div>
        
        <!-- Views Count Badge - Bottom Right -->
        <div class="views-count">
            <i class="bi bi-eye"></i>
            <?php echo number_format($property['ViewsCount'] ?? 0); ?>
        </div>
    </div>
    
    <!-- Content -->
    <div class="property-content">
        <!-- Header -->
        <div class="property-header">
            <h5 class="property-title">
                <?php echo htmlspecialchars($property['StreetAddress']); ?>
            </h5>
            <div class="property-location">
                <i class="bi bi-geo-alt-fill"></i>
                <?php echo $full_location; ?>
            </div>
        </div>
        
        <!-- Price -->
        <div class="property-price">
            <?php echo $is_for_rent ? $rent_display : $formatted_price; ?>
            <?php if ($is_for_rent): ?>
                <span class="price-period">/ month</span>
            <?php endif; ?>
        </div>
        
        <!-- Property Stats Grid -->
        <div class="property-stats">
            <div class="stat-item">
                <span class="stat-value">
                    <i class="bi bi-door-closed" style="font-size: 0.875rem; color: #bc9e42;"></i>
                    <?php echo htmlspecialchars($property['Bedrooms'] ?? '—'); ?>
                </span>
                <span class="stat-label">Beds</span>
            </div>
            <div class="stat-item">
                <span class="stat-value">
                    <i class="bi bi-droplet" style="font-size: 0.875rem; color: #bc9e42;"></i>
                    <?php echo htmlspecialchars($property['Bathrooms'] ?? '—'); ?>
                </span>
                <span class="stat-label">Baths</span>
            </div>
            <div class="stat-item">
                <span class="stat-value">
                    <i class="bi bi-rulers" style="font-size: 0.875rem; color: #bc9e42;"></i>
                    <?php echo !empty($property['SquareFootage']) ? number_format($property['SquareFootage']) : '—'; ?>
                </span>
                <span class="stat-label">SqFt</span>
            </div>
        </div>
        
        <!-- Rental Details (if For Rent) -->
        <?php if ($is_for_rent): ?>
        <div class="rental-details-compact">
            <div class="rental-details-grid">
                <div class="rental-detail-item">
                    <span class="rental-detail-label"><i class="bi bi-shield-lock"></i> Deposit</span>
                    <span class="rental-detail-value"><?php echo $deposit_display ?? '—'; ?></span>
                </div>
                <div class="rental-detail-item">
                    <span class="rental-detail-label"><i class="bi bi-calendar2-week"></i> Lease</span>
                    <span class="rental-detail-value"><?php echo $lease_display ?? '—'; ?></span>
                </div>
                <div class="rental-detail-item">
                    <span class="rental-detail-label"><i class="bi bi-lamp"></i> Furnishing</span>
                    <span class="rental-detail-value"><?php echo $furnish_display ?? '—'; ?></span>
                </div>
                <div class="rental-detail-item">
                    <span class="rental-detail-label"><i class="bi bi-calendar-check"></i> Available</span>
                    <span class="rental-detail-value"><?php echo $avail_display ?? '—'; ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Property Details -->
        <div class="property-details">
            <div class="detail-row">
                <span class="detail-label">
                    <i class="bi bi-hash"></i>
                    Property ID
                </span>
                <span class="detail-value">#<?php echo htmlspecialchars($property['property_ID']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">
                    <i class="bi bi-calendar3"></i>
                    Year Built
                </span>
                <span class="detail-value"><?php echo htmlspecialchars($property['YearBuilt'] ?? 'N/A'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">
                    <i class="bi bi-p-square"></i>
                    Parking
                </span>
                <span class="detail-value"><?php echo htmlspecialchars($property['ParkingType'] ?? 'N/A'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">
                    <i class="bi bi-calendar-event"></i>
                    Listed
                </span>
                <span class="detail-value"><?php echo $listing_date; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">
                    <i class="bi bi-clock-history"></i>
                    Days on Market
                </span>
                <span class="detail-value"><?php echo $days_on_market; ?> days</span>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="property-footer">
            <div class="posted-by">
                <i class="bi bi-person-circle"></i>
                <?php echo $poster_info; ?>
            </div>
            <a href="view_property.php?id=<?php echo $property['property_ID']; ?>" class="action-button">
                <i class="bi bi-eye-fill"></i>
                View & Manage
            </a>
        </div>
    </div>
</div>