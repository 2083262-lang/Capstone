<?php
// This template is included in agent.php to display each agent card.
// The $agent variable is available from the parent file's loop.

$full_name = htmlspecialchars(trim($agent['first_name'] . ' ' . $agent['last_name']));
$middle_name = !empty($agent['middle_name']) ? ' ' . htmlspecialchars($agent['middle_name']) : '';
$registered_date = date("M d, Y", strtotime($agent['date_registered']));

// Determine the agent's status and corresponding badge style
if (!$agent['profile_completed']) {
    $status_text = 'Needs Profile';
    $status_class = 'status-needs-profile';
} elseif ($agent['is_approved']) {
    $status_text = 'Approved';
    $status_class = 'status-approved';
} elseif (!$agent['is_active'] && !empty($agent['rejection_reason'])) {
    $status_text = 'Rejected';
    $status_class = 'status-rejected';
} else {
    $status_text = 'Pending';
    $status_class = 'status-pending';
}

// Handle profile image
$profile_image = !empty($agent['profile_picture_url']) ? 
                 htmlspecialchars($agent['profile_picture_url']) : 
                 'https://via.placeholder.com/90x90/bc9e42/ffffff?text=' . strtoupper(substr($agent['first_name'], 0, 1));

// Format experience
$experience = $agent['years_experience'] ? $agent['years_experience'] . ' years' : 'New agent';
?>

<?php
// This template is included in agent.php to display each agent card.
// The $agent variable is available from the parent file's loop.

$full_name = htmlspecialchars(trim($agent['first_name'] . ' ' . $agent['last_name']));
$middle_name = !empty($agent['middle_name']) ? ' ' . htmlspecialchars($agent['middle_name']) : '';
$registered_date = date("M d, Y", strtotime($agent['date_registered']));

// Determine the agent's status and corresponding badge style
if (!$agent['profile_completed']) {
    $status_text = 'Needs Profile';
    $status_class = 'status-needs-profile';
} elseif ($agent['is_approved']) {
    $status_text = 'Approved';
    $status_class = 'status-approved';
} elseif (!$agent['is_active'] && !empty($agent['rejection_reason'])) {
    $status_text = 'Rejected';
    $status_class = 'status-rejected';
} else {
    $status_text = 'Pending';
    $status_class = 'status-pending';
}

// Handle profile image
$profile_image = !empty($agent['profile_picture_url']) ? 
                 htmlspecialchars($agent['profile_picture_url']) : 
                 'https://via.placeholder.com/100x100/bc9e42/ffffff?text=' . strtoupper(substr($agent['first_name'], 0, 1));

// Format experience
$experience = $agent['years_experience'] ? $agent['years_experience'] . ' years' : 'New agent';
?>

<div class="col-xl-4 col-lg-6 col-md-6 mb-4">
    <div class="agent-card card h-100">
        <div class="card-header text-center position-relative border-0 pb-0">
            <span class="badge status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
            <img src="<?php echo $profile_image; ?>" 
                 class="agent-profile-img" 
                 alt="<?php echo $full_name; ?> Profile Picture"
                 onerror="this.src='https://via.placeholder.com/100x100/bc9e42/ffffff?text=<?php echo strtoupper(substr($agent['first_name'], 0, 1)); ?>'">
        </div>
        
        <div class="card-body text-center d-flex flex-column">
            <!-- Agent Name and Specialty -->
            <div>
                <h5 class="agent-name"><?php echo $full_name; ?></h5>
                <p class="agent-specialty text-muted">
                    <?php echo htmlspecialchars($agent['specialization'] ?? 'Specialization not set'); ?>
                </p>
            </div>
            
            <!-- Agent Information List -->
            <ul class="list-group list-group-flush text-start small my-3 flex-grow-1">
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span><i class="bi bi-patch-check-fill text-muted me-2"></i>License No.</span>
                    <span class="fw-bold text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($agent['license_number'] ?? 'N/A'); ?>">
                        <?php echo htmlspecialchars($agent['license_number'] ?? 'N/A'); ?>
                    </span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span><i class="bi bi-envelope-fill text-muted me-2"></i>Email</span>
                    <span class="fw-bold text-truncate ms-2" style="max-width: 150px;" title="<?php echo htmlspecialchars($agent['email']); ?>">
                        <?php echo htmlspecialchars($agent['email']); ?>
                    </span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span><i class="bi bi-telephone-fill text-muted me-2"></i>Phone</span>
                    <span class="fw-bold">
                        <?php echo htmlspecialchars($agent['phone_number'] ?? 'N/A'); ?>
                    </span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span><i class="bi bi-award-fill text-muted me-2"></i>Experience</span>
                    <span class="fw-bold" style="color: var(--secondary-color);">
                        <?php echo $experience; ?>
                    </span>
                </li>
            </ul>

            <!-- Rejection Reason (if applicable) -->
            <?php if ($status_text === 'Rejected' && !empty($agent['rejection_reason'])): ?>
            <div class="rejection-reason" title="<?php echo htmlspecialchars($agent['rejection_reason']); ?>">
                <strong>Reason:</strong> <?php echo htmlspecialchars($agent['rejection_reason']); ?>
            </div>
            <?php endif; ?>

            <!-- Agent Meta Information -->
            <div class="mt-auto w-100">
                <p class="text-muted small mb-2">
                    <i class="fas fa-calendar-plus me-1"></i>
                    Registered: <?php echo $registered_date; ?>
                </p>
                <div class="d-grid">
                    <a href="review_agent_details.php?account_id=<?php echo $agent['account_id']; ?>" 
                       class="btn btn-sm btn-outline-dark">
                        <i class="fas fa-eye me-1"></i>View & Manage
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>