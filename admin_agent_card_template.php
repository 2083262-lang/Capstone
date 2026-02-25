<?php
// admin_agent_card_template.php
// Included in agent.php to render each agent card.
// The $agent variable is available from the parent file's loop.

$full_name = htmlspecialchars(trim($agent['first_name'] . ' ' . $agent['last_name']));
$full_name_with_middle = htmlspecialchars(trim($agent['first_name'] . ' ' . ($agent['middle_name'] ?? '') . ' ' . $agent['last_name']));
$registered_date = date("M d, Y", strtotime($agent['date_registered']));

// Determine status
if (!$agent['profile_completed']) {
    $status_text = 'Incomplete';
    $status_class = 'incomplete';
} elseif ($agent['is_approved'] && $agent['is_active']) {
    $status_text = 'Approved';
    $status_class = 'approved';
} elseif (!$agent['is_active'] && !empty($agent['rejection_reason'])) {
    $status_text = 'Rejected';
    $status_class = 'rejected';
} else {
    $status_text = 'Pending';
    $status_class = 'pending';
}

// Handle profile image
$profile_image = !empty($agent['profile_picture_url']) ? 
                 htmlspecialchars($agent['profile_picture_url']) : 
                 'https://ui-avatars.com/api/?name=' . urlencode($agent['first_name'] . '+' . $agent['last_name']) . '&background=d4af37&color=fff&size=120&font-size=0.4&bold=true';

// Format experience
$experience = $agent['years_experience'] ? $agent['years_experience'] . ' yrs' : 'N/A';
?>

<div class="agent-card" 
     data-search-name="<?php echo htmlspecialchars(strtolower($full_name_with_middle)); ?>"
     data-search-email="<?php echo htmlspecialchars(strtolower($agent['email'] ?? '')); ?>"
     data-search-license="<?php echo htmlspecialchars(strtolower($agent['license_number'] ?? '')); ?>"
     data-search-specialty="<?php echo htmlspecialchars(strtolower($agent['specialization'] ?? '')); ?>"
     data-search-phone="<?php echo htmlspecialchars(strtolower($agent['phone_number'] ?? '')); ?>"
     data-experience="<?php echo (int)($agent['years_experience'] ?? 0); ?>"
     data-registered="<?php echo htmlspecialchars($agent['date_registered']); ?>"
     data-active="<?php echo $agent['is_active'] ? '1' : '0'; ?>">
    
    <!-- Header with avatar -->
    <div class="card-header-section">
        <span class="status-badge <?php echo $status_class; ?>">
            <i class="bi bi-<?php echo $status_class === 'approved' ? 'check-circle-fill' : ($status_class === 'pending' ? 'clock-fill' : ($status_class === 'rejected' ? 'x-circle-fill' : 'exclamation-circle-fill')); ?>"></i>
            <?php echo $status_text; ?>
        </span>
        <img src="<?php echo $profile_image; ?>" 
             class="agent-avatar" 
             alt="<?php echo $full_name; ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($agent['first_name'] . '+' . $agent['last_name']); ?>&background=d4af37&color=fff&size=120&font-size=0.4&bold=true'">
        <div class="agent-header-info">
            <h5 class="agent-name" title="<?php echo $full_name_with_middle; ?>"><?php echo $full_name; ?></h5>
            <p class="agent-specialty-text"><?php echo htmlspecialchars($agent['specialization'] ?? 'Specialization not set'); ?></p>
        </div>
    </div>
    
    <!-- Body -->
    <div class="card-body-content">
        <div class="info-row">
            <i class="bi bi-patch-check-fill"></i>
            <span class="info-label">License</span>
            <span class="info-value" title="<?php echo htmlspecialchars($agent['license_number'] ?? 'N/A'); ?>">
                <?php echo htmlspecialchars($agent['license_number'] ?? 'N/A'); ?>
            </span>
        </div>
        <div class="info-row">
            <i class="bi bi-envelope-fill"></i>
            <span class="info-label">Email</span>
            <span class="info-value" title="<?php echo htmlspecialchars($agent['email']); ?>">
                <?php echo htmlspecialchars($agent['email']); ?>
            </span>
        </div>
        <div class="info-row">
            <i class="bi bi-telephone-fill"></i>
            <span class="info-label">Phone</span>
            <span class="info-value">
                <?php echo htmlspecialchars($agent['phone_number'] ?? 'N/A'); ?>
            </span>
        </div>
        <div class="info-row">
            <i class="bi bi-award-fill"></i>
            <span class="info-label">Experience</span>
            <span class="info-value" style="color: var(--gold-dark); font-weight: 700;">
                <?php echo $experience; ?>
            </span>
        </div>

        <?php if ($status_text === 'Rejected' && !empty($agent['rejection_reason'])): ?>
        <div class="rejection-box" title="<?php echo htmlspecialchars($agent['rejection_reason']); ?>">
            <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Reason:</strong> <?php echo htmlspecialchars($agent['rejection_reason']); ?>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="card-footer-section">
            <div class="meta-row">
                <i class="bi bi-calendar3"></i>
                Registered: <?php echo $registered_date; ?>
            </div>
            <a href="review_agent_details.php?account_id=<?php echo $agent['account_id']; ?>" class="btn-manage">
                <i class="bi bi-eye"></i>
                View & Manage
            </a>
        </div>
    </div>
</div>
