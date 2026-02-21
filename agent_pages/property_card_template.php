<?php
// This is a template file included in agent_property.php
// The $property variable is available from the parent file's loop.

// --- Determine badge class and text based on approval status and property status ---
$badge_class = '';
$status_text = '';

// First check if it's sold or pending sold
if ($property['Status'] === 'Pending Sold') {
    $badge_class = 'bg-info-subtle text-info-emphasis';
    $status_text = 'Pending Sold';
} else if ($property['Status'] === 'Sold') {
    $badge_class = 'bg-dark-subtle text-dark-emphasis';
    $status_text = 'Sold';
} else {
    // If not, use the approval status
    switch ($property['approval_status']) {
        case 'approved':
            $badge_class = 'bg-success-subtle text-success-emphasis';
            $status_text = 'Live';
            break;
        case 'pending':
            $badge_class = 'bg-warning-subtle text-warning-emphasis';
            $status_text = 'Pending Review';
            break;
        case 'rejected':
            $badge_class = 'bg-danger-subtle text-danger-emphasis';
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
$listing_date = date("M d, Y", strtotime($property['ListingDate']));

// --- NEW: Calculate Days On Market in real-time ---
$listingDateObj = new DateTime($property['ListingDate']);
$today = new DateTime();
$interval = $today->diff($listingDateObj);
$days_on_market = $interval->days;

?>

<div class="col-lg-4 col-md-6 mb-4">
    <div class="card property-card h-100">
        <div class="property-card-img-container">
            <img src="../<?php echo htmlspecialchars($property['PhotoURL'] ?? 'https://via.placeholder.com/400x280?text=No+Image'); ?>" class="property-card-img" alt="Property Image">
            <span class="badge approval-status-badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span>
        </div>
        <div class="property-card-body">
            <div>
                <p class="property-location small text-muted mb-1">
                    <?php echo htmlspecialchars($property['City']); ?>, <?php echo htmlspecialchars($property['State']); ?>
                </p>
                <h5 class="property-title">
                    <?php echo htmlspecialchars($property['StreetAddress']); ?>
                </h5>
                <div class="property-price mt-2">
                    <?php echo $is_for_rent ? $rent_display : $formatted_price; ?>
                    <?php if ($is_for_rent): ?><span class="text-muted" style="font-size:0.85rem; font-weight:600;">/ month</span><?php endif; ?>
                </div>
                
                <div class="d-flex justify-content-start text-muted small gap-3 my-3">
                    <span><i class="fas fa-bed me-1"></i> <b><?php echo htmlspecialchars($property['Bedrooms'] ?? 0); ?></b> Beds</span>
                    <span><i class="fas fa-bath me-1"></i> <b><?php echo htmlspecialchars($property['Bathrooms'] ?? 0); ?></b> Baths</span>
                    <span><i class="fas fa-ruler-combined me-1"></i> <b><?php echo number_format($property['SquareFootage'] ?? 0); ?></b> sqft</span>
                </div>
                <?php if ($is_for_rent): ?>
                <div class="text-muted small mt-1">
                    <i class="fas fa-shield-alt me-1"></i> Deposit: <b><?php echo $deposit_display ?? '—'; ?></b>
                    &nbsp;•&nbsp; <i class="fas fa-calendar me-1"></i> Lease: <b><?php echo $lease_display ?? '—'; ?></b>
                    &nbsp;•&nbsp; <i class="fas fa-couch me-1"></i> Furnishing: <b><?php echo $furnish_display ?? '—'; ?></b>
                    &nbsp;•&nbsp; <i class="fas fa-calendar-day me-1"></i> Available: <b><?php echo $avail_display ?? '—'; ?></b>
                </div>
                <?php endif; ?>
            </div>
            <div class="property-footer">
                <div class="d-flex justify-content-between small text-muted mb-2">
                    <span>
                        <i class="fas fa-tag fa-xs me-1"></i>
                        <?php echo htmlspecialchars($property['Status']); ?>
                    </span>
                    <span>
                        <i class="fas fa-calendar-alt fa-xs me-1"></i>
                        <?php echo $days_on_market; ?> Days on Market
                    </span>
                </div>
                 <div class="d-grid gap-2">
                    <a href="view_agent_property.php?id=<?php echo $property['property_ID']; ?>" class="btn btn-sm btn-outline-dark">View Details</a>
                    <?php if ($property['approval_status'] === 'approved' && $property['Status'] != 'Pending Sold' && $property['Status'] != 'Sold'): ?>
                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#markSoldModal" 
                                data-property-id="<?php echo $property['property_ID']; ?>" 
                                data-property-title="<?php echo htmlspecialchars($property['StreetAddress']); ?>">
                            <i class="fas fa-check-circle me-1"></i>Mark as Sold
                        </button>
                    <?php elseif ($property['Status'] === 'Pending Sold'): ?>
                        <span class="badge bg-info text-dark d-block p-2 mt-2">
                            <i class="fas fa-hourglass-half me-1"></i>Sale Verification in Progress
                        </span>
                    <?php elseif ($property['Status'] === 'Sold'): ?>
                        <span class="badge bg-dark d-block p-2 mt-2">
                            <i class="fas fa-check-double me-1"></i>Sale Completed
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>