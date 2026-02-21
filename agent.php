<?php
session_start();
include 'connection.php'; // Include your database connection

// Check if the user is logged in AND their role is 'admin'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit(); 
}

// UPDATED: Fetch all agents and their information, including the latest rejection reason
$sql = "SELECT
            a.account_id, a.first_name, a.middle_name, a.last_name,
            a.phone_number, a.email, a.date_registered, a.is_active,
            ai.license_number, ai.specialization, ai.profile_picture_url,
            ai.profile_completed, ai.is_approved, ai.years_experience,
            (SELECT sl.reason_message 
             FROM status_log sl 
             WHERE sl.item_id = a.account_id 
               AND sl.item_type = 'agent' 
               AND sl.action = 'rejected' 
             ORDER BY sl.log_timestamp DESC 
             LIMIT 1) AS rejection_reason
        FROM
            accounts a
        LEFT JOIN
            agent_information ai ON a.account_id = ai.account_id
        WHERE
            a.role_id = 2 -- Role ID for 'agent'
        ORDER BY
            a.date_registered DESC";

$result = $conn->query($sql);
$all_agents = $result->fetch_all(MYSQLI_ASSOC);

// Categorize agents using PHP
$agents_pending_approval = array_filter($all_agents, fn($agent) => $agent['profile_completed'] && !$agent['is_approved'] && $agent['is_active']);
$agents_approved = array_filter($all_agents, fn($agent) => $agent['profile_completed'] && $agent['is_approved'] && $agent['is_active']);
$agents_needs_profile = array_filter($all_agents, fn($agent) => !$agent['profile_completed']);
$agents_rejected = array_filter($all_agents, fn($agent) => !$agent['is_active'] && !empty($agent['rejection_reason']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --background-color: #f8f4f4;
            --card-bg-color: #ffffff;
            --border-color: #e6e6e6;
            --text-muted: #6c757d;
            --shadow-light: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-medium: 0 4px 12px rgba(0,0,0,0.1);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background-color);
            margin: 0;
        }

        .content-wrapper {
            margin-left: var(--sidebar-width);
            padding: var(--content-padding);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
            }
        }

        .page-banner {
            background: linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4)), url('images/agentBanner.jpg') no-repeat center center;
            background-size: cover;
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }

        .page-banner h1 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-banner p {
            opacity: 0.9;
            margin-bottom: 0;
        }

        .add-btn {
            background-color: var(--secondary-color);
            color: #fff;
            border: none;
            font-weight: 600;
            border-radius: 8px;
            padding: 10px 20px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .add-btn:hover {
            background-color: #a38736;
            color: #fff;
            transform: translateY(-1px);
        }

        /* Tab Interface Styles */
        .listings-tabs .nav-link {
            color: var(--primary-color);
            font-weight: 600;
            border-bottom: 3px solid transparent;
            padding: 1rem 1.5rem;
            transition: all 0.3s ease;
        }

        .listings-tabs .nav-link.active {
            color: var(--background-color);
            border-bottom-color: var(--secondary-color);
            background-color: transparent;
        }

        .listings-tabs .nav-link:hover:not(.active) {
            color: var(--secondary-color);
            border-bottom-color: rgba(188, 158, 66, 0.3);
        }

        .empty-tab-message {
            background-color: var(--card-bg-color);
            padding: 4rem 2rem;
            border-radius: 12px;
            text-align: center;
            border: 2px dashed var(--border-color);
            color: var(--text-muted);
        }

        .empty-tab-message i {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }

        /* Agent Card Design */
        .agent-card {
            background: var(--card-bg-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: var(--shadow-light);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }

        .agent-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
            border-color: var(--secondary-color);
        }

        .agent-card .card-header {
            background: linear-gradient(135deg, var(--secondary-color), #d4b966);
            height: 80px;
            border: none;
            position: relative;
        }

        .agent-profile-img {
            width: 90px;
            height: 90px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid var(--card-bg-color);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
          
            background-color: var(--card-bg-color);
            transition: transform 0.3s ease;
        }

        .agent-card:hover .agent-profile-img {
            transform: scale(1.05);
        }

        .agent-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-top: 0.75rem;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .agent-specialty {
            font-size: 0.85rem;
            color: var(--text-muted);
            min-height: 40px;
            line-height: 1.4;
            margin-bottom: 1rem;
        }

        .agent-card .list-group-item {
            background-color: transparent;
            border: none;
            padding: 0.5rem 0;
            font-size: 0.9rem;
        }

        .agent-info {
            color: var(--secondary-color);
            font-weight: 500;
        }

        .status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-approved {
            background-color: rgba(40, 167, 69, 0.9);
            color: white;
        }

        .status-pending {
            background-color: rgba(255, 193, 7, 0.9);
            color: #333;
        }

        .status-needs-profile {
            background-color: rgba(13, 202, 240, 0.9);
            color: white;
        }

        .status-rejected {
            background-color: rgba(220, 53, 69, 0.9);
            color: white;
        }

        .rejection-reason {
            background: linear-gradient(135deg, #f8d7da, #f5c2c7);
            color: #842029;
            border-left: 4px solid #dc3545;
            padding: 0.50rem 0.75rem 4px 0.75rem;
            margin-top: 1rem;
            font-size: 0.85rem;
            border-radius: 6px;
            text-align: left;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            word-break: break-word;
        }

        .agent-card .card-body {
            padding: 1.25rem;
        }

        .view-manage-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
            width: 100%;
            text-align: center;
        }

        .view-manage-btn:hover {
            background-color: var(--secondary-color);
            color: white;
            transform: translateY(-1px);
        }

        .agent-meta {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* Responsive Design - Remove duplicate, handled above */
        @media (max-width: 768px) {
            .page-banner {
                padding: 30px 20px;
            }

            .page-banner h1 {
                font-size: 1.8rem;
            }

            .agent-name {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
     <!-- Include Sidebar -->
    <?php include 'admin_sidebar.php'; ?>
    <!-- Include Navbar -->
    <?php include 'admin_navbar.php'; ?>

    <div class="content-wrapper">
        <div class="page-banner">
                <h1><i class="fas fa-user-tie me-2"></i>Agent Management</h1>
                <p>Review new agent applications and manage all active agents in your real estate system.</p>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Agent Overview</h3>
                    <p class="text-muted mb-0">Total Agents: <i class="fas fa-user-tie ms-1"></i> <?php echo count($all_agents); ?></p>
                </div>
            </div>
            
            <div class="listings-tabs">
                <ul class="nav nav-tabs mb-4" id="agentStatusTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-content" type="button" role="tab">
                            Pending <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill ms-1"><?php echo count($agents_pending_approval); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved-content" type="button" role="tab">
                            Approved <span class="badge bg-success-subtle text-success-emphasis rounded-pill ms-1"><?php echo count($agents_approved); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected-content" type="button" role="tab">
                            Rejected <span class="badge bg-danger-subtle text-danger-emphasis rounded-pill ms-1"><?php echo count($agents_rejected); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="needs-profile-tab" data-bs-toggle="tab" data-bs-target="#needs-profile-content" type="button" role="tab">
                            Incomplete <span class="badge bg-info-subtle text-info-emphasis rounded-pill ms-1"><?php echo count($agents_needs_profile); ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="agentStatusTabsContent">
                    <!-- Pending Approval -->
                    <div class="tab-pane fade show active" id="pending-content" role="tabpanel">
                        <?php if (empty($agents_pending_approval)): ?>
                            <div class="empty-tab-message">
                                <i class="fas fa-clock"></i>
                                <h5>No Pending Agents</h5>
                                <p class="mb-0">No agents are currently pending approval.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-4">
                                <?php foreach ($agents_pending_approval as $agent): include 'admin_agent_card_template.php'; endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Approved Agents -->
                    <div class="tab-pane fade" id="approved-content" role="tabpanel">
                        <?php if (empty($agents_approved)): ?>
                            <div class="empty-tab-message">
                                <i class="fas fa-check-circle"></i>
                                <h5>No Approved Agents</h5>
                                <p class="mb-0">No approved agents found.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-4">
                                <?php foreach ($agents_approved as $agent): include 'admin_agent_card_template.php'; endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Rejected Agents -->
                    <div class="tab-pane fade" id="rejected-content" role="tabpanel">
                        <?php if (empty($agents_rejected)): ?>
                            <div class="empty-tab-message">
                                <i class="fas fa-times-circle"></i>
                                <h5>No Rejected Agents</h5>
                                <p class="mb-0">No agents have been rejected.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-4">
                                <?php foreach ($agents_rejected as $agent): include 'admin_agent_card_template.php'; endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Incomplete Profile -->
                    <div class="tab-pane fade" id="needs-profile-content" role="tabpanel">
                        <?php if (empty($agents_needs_profile)): ?>
                            <div class="empty-tab-message">
                                <i class="fas fa-user-edit"></i>
                                <h5>No Incomplete Profiles</h5>
                                <p class="mb-0">No agents need to complete their profile.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-4">
                                <?php foreach ($agents_needs_profile as $agent): include 'admin_agent_card_template.php'; endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add smooth animations to cards
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -30px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Apply animation to agent cards
    document.querySelectorAll('.agent-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(15px)';
        card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        observer.observe(card);
    });

    // Add hover effects for better interactivity
    document.querySelectorAll('.agent-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.borderColor = 'var(--secondary-color)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.borderColor = 'var(--border-color)';
        });
    });
});
</script>
</body>
</html>