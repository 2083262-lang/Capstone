<?php
// Start output buffering
ob_start();
session_start();
include 'connection.php';
include 'review_agent_details_process.php';

$sql_fetch_agent = "SELECT 
                        a.account_id, a.first_name, a.middle_name, a.last_name, a.email, a.phone_number, a.date_registered, a.username, a.is_active,
                        ai.license_number, ai.specialization, ai.years_experience,
                        ai.bio, ai.profile_picture_url,
                        ai.profile_completed, ai.is_approved,
                        (SELECT sl.reason_message 
                         FROM status_log sl 
                         WHERE sl.item_id = a.account_id AND sl.item_type = 'agent' AND sl.action = 'rejected' 
                         ORDER BY sl.log_timestamp DESC LIMIT 1) AS rejection_reason
                    FROM accounts a
                    LEFT JOIN agent_information ai ON a.account_id = ai.account_id
                    WHERE a.account_id = ? AND a.role_id = (SELECT role_id FROM user_roles WHERE role_name = 'agent')";

$stmt_fetch_agent = $conn->prepare($sql_fetch_agent);
$stmt_fetch_agent->bind_param("i", $account_id_to_review);
$stmt_fetch_agent->execute();
$result_fetch_agent = $stmt_fetch_agent->get_result();

if ($result_fetch_agent->num_rows > 0) {
    $agent_data = $result_fetch_agent->fetch_assoc();
} else {
    $error_message = "Agent not found or is not an agent role.";
}

$stmt_fetch_agent->close();
// Keep the connection open so included navbar/sidebar can access admin profile if needed
// $conn->close() will be handled at the end of the script if necessary
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Agent - <?php echo htmlspecialchars($agent_data['first_name'] ?? ''); ?> - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --background-color: #f8f9fa;
            --card-bg-color: #ffffff;
            --border-color: #e9ecef;
            --success-color: #22c55e;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --text-muted: #6b7280;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background-color);
            font-weight: 400;
            line-height: 1.6;
        }

        /* Sidebar and navbar styles (unchanged) */
        .sidebar { background-color: #161209; color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; padding-top: 30px; display: flex; flex-direction: column; }
        .sidebar-logo { text-align: center; padding: 20px; margin-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar a { display: block; color: #f8f4f4; padding: 15px 30px; text-decoration: none; font-weight: 500; font-size: 1.1rem; transition: background-color 0.3s ease, color 0.3s ease; }
        .sidebar a:hover { background-color: #bc9e42; color: #161209; }
        .sidebar .active { background-color: #bc9e42; color: #161209; }
        .sidebar a.text-danger { margin-top: auto; padding-top: 20px; padding-bottom: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .navbar-custom { background-color: var(--card-bg-color); border-bottom: 1px solid var(--border-color); height: 70px; margin-left: 290px; padding: 0 20px; }
        .content { margin-left: 290px; padding: 0; min-height: 100vh; }

        /* Hero Section */
        .agent-hero {
            background: linear-gradient(135deg, var(--primary-color) 0%, rgba(22, 18, 9, 0.9) 100%);
            color: white;
            padding: 3rem 0;
            position: relative;
            overflow: hidden;
        }

        .agent-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(188,158,66,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(188,158,66,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(188,158,66,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>') repeat;
            opacity: 0.3;
        }

        .agent-hero-content {
            position: relative;
            z-index: 2;
        }

        .agent-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--secondary-color);
            box-shadow: var(--shadow-lg);
            margin-bottom: 2rem;
        }

        .agent-name {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .agent-specialty {
            font-size: 1.25rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 1rem;
        }

        .agent-quick-stats {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .quick-stat {
            background: rgba(188, 158, 66, 0.2);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(188, 158, 66, 0.3);
            text-align: center;
            min-width: 120px;
        }

        .quick-stat-value {
            display: block;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--secondary-color);
        }

        .quick-stat-label {
            font-size: 0.85rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Status badge in hero */
        .hero-status-badge {
            position: absolute;
            top: 2rem;
            right: 2rem;
            z-index: 3;
            font-weight: 600;
            font-size: 1rem;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-approved { background: rgba(34, 197, 94, 0.9); color: white; }
        .badge-pending { background: rgba(245, 158, 11, 0.9); color: white; }
        .badge-rejected { background: rgba(239, 68, 68, 0.9); color: white; }
        .badge-needs-profile { background: rgba(59, 130, 246, 0.9); color: white; }

        /* Content sections */
        .agent-content {
            padding: 2rem 0;
        }

        .content-section {
            background: var(--card-bg-color);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .content-section:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--secondary-color);
            font-size: 1.25rem;
        }

        /* Info grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--background-color) 0%, rgba(248, 249, 250, 0.5) 100%);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .info-item:hover {
            background: rgba(188, 158, 66, 0.05);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        .info-icon {
            width: 45px;
            height: 45px;
            background: var(--secondary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
            word-break: break-word;
        }

        /* Bio section */
        .agent-bio {
            font-size: 1.1rem;
            line-height: 1.8;
            color: #374151;
            white-space: pre-wrap;
            word-break: break-word;
            overflow-wrap: break-word;
            padding: 1.5rem;
            background: var(--background-color);
            border-radius: 12px;
            border-left: 4px solid var(--secondary-color);
        }

        /* Rejection notice */
        .rejection-notice {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.05) 100%);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-left: 4px solid var(--danger-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .rejection-title {
            color: var(--danger-color);
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .rejection-message {
            color: #991b1b;
            font-style: italic;
            font-size: 1rem;
            line-height: 1.6;
            margin: 0;
        }

        /* Action panel */
        .action-panel {
            position: sticky;
            top: 90px;
            z-index: 10;
        }

        .action-card {
            background: var(--card-bg-color);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }

        .action-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        /* Modern buttons */
        .btn-modern {
            font-weight: 600;
            padding: 0.875rem 2rem;
            border-radius: 12px;
            border: none;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-modern:hover::before {
            left: 100%;
        }

        .btn-approve {
            background: linear-gradient(135deg, var(--success-color) 0%, #16a34a 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .btn-approve:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
            color: white;
        }

        .btn-reject {
            background: linear-gradient(135deg, var(--danger-color) 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-reject:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
            color: white;
        }

        .btn-secondary-modern {
            background: var(--background-color);
            color: var(--primary-color);
            border: 2px solid var(--border-color);
        }

        .btn-secondary-modern:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }

        .btn-modern:disabled {
            opacity: 0.6;
            transform: none !important;
            cursor: not-allowed;
        }

        .btn-modern:disabled::before {
            display: none;
        }

        /* Modal improvements */
        .modal-content {
            border: none;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            background: var(--primary-color);
            color: white;
            border-radius: 16px 16px 0 0;
            padding: 1.5rem 2rem;
            border-bottom: none;
        }

        .modal-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--border-color);
            border-radius: 0 0 16px 16px;
        }

        /* Alert improvements */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 197, 94, 0.05) 100%);
            color: #166534;
            border-left-color: var(--success-color);
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.05) 100%);
            color: #991b1b;
            border-left-color: var(--danger-color);
        }

        /* Error state */
        .error-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--card-bg-color);
            border-radius: 16px;
            margin: 2rem;
            box-shadow: var(--shadow);
        }

        .error-icon {
            font-size: 4rem;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .agent-name { font-size: 2.2rem; }
            .agent-quick-stats { justify-content: center; }
            .quick-stat { min-width: 100px; }
            .content-section { padding: 1.5rem; }
            .info-grid { grid-template-columns: 1fr; }
            .hero-status-badge { position: relative; top: auto; right: auto; margin-bottom: 1rem; }
        }

        @media (max-width: 992px) {
            .sidebar { left: -290px; }
            .content, .navbar-custom { margin-left: 0; }
            .action-panel { position: relative; top: auto; }
        }

        /* Loading animation */
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 8px;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Form enhancements */
        .form-control {
            border-radius: 8px;
            border: 2px solid var(--border-color);
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(188, 158, 66, 0.25);
        }

        .form-label {
            font-weight: 500;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        /* Agent Action Forms - Specific styling to avoid conflicts */
        .agent-action-form {
            width: 100%;
        }

        #approveAgentForm {
            margin-bottom: 0;
        }

        .btn-approve-agent {
            width: 100%;
        }

        .btn-reject-agent {
            width: 100%;
        }

        .btn-back-to-list {
            width: 100%;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--background-color);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }
    </style>
</head>
<body>

<?php
// Use shared admin sidebar and navbar to preserve consistent design
// Mark 'agent.php' as active in the sidebar since this page is part of the Agents workflow
$active_page = 'agent.php';
include 'admin_sidebar.php';
include 'admin_navbar.php';
?>

<div class="content">
    <?php if ($error_message): ?>
        <div class="container-fluid px-4 pt-4">
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_message; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($success_message): ?>
        <div class="container-fluid px-4 pt-4">
            <div class="alert alert-success" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($agent_data): ?>
        <?php
            $full_name = htmlspecialchars(trim($agent_data['first_name'] . ' ' . $agent_data['middle_name'] . ' ' . $agent_data['last_name']));
            
            $is_profile_incomplete = ($agent_data['profile_completed'] == 0);
            $is_rejected = (!$agent_data['is_active'] && !$agent_data['is_approved']);

            if ($is_profile_incomplete) {
                $status_text = 'Needs Profile Info';
                $status_class = 'badge-needs-profile';
            } elseif ($is_rejected) {
                $status_text = 'Rejected';
                $status_class = 'badge-rejected';
            } elseif (!$agent_data['is_approved']) {
                $status_text = 'Pending Approval';
                $status_class = 'badge-pending';
            } else {
                $status_text = 'Approved';
                $status_class = 'badge-approved';
            }
        ?>

        <!-- Hero Section -->
        <div class="agent-hero">
            <!-- Status Badge -->
            <div class="hero-status-badge <?php echo $status_class; ?>">
                <?php echo $status_text; ?>
            </div>
            
            <div class="agent-hero-content">
                <div class="container-fluid px-4">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="text-center text-md-start">
                                <img src="<?php echo htmlspecialchars($agent_data['profile_picture_url'] ?? 'https://via.placeholder.com/150?text=N/A'); ?>" 
                                     alt="Agent Profile Picture" class="agent-avatar">
                                <h1 class="agent-name"><?php echo $full_name; ?></h1>
                                <p class="agent-specialty"><?php echo htmlspecialchars($agent_data['specialization'] ?? 'Real Estate Agent'); ?></p>
                                
                                <div class="agent-quick-stats">
                                    <div class="quick-stat">
                                        <span class="quick-stat-value"><?php echo htmlspecialchars($agent_data['years_experience'] ?? '0'); ?></span>
                                        <span class="quick-stat-label">Years Experience</span>
                                    </div>
                                    
                                    <div class="quick-stat">
                                        <span class="quick-stat-value"><?php echo date('M Y', strtotime($agent_data['date_registered'])); ?></span>
                                        <span class="quick-stat-label">Registered</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="agent-content">
            <div class="container-fluid px-4">
                <div class="row">
                    <div class="col-lg-8">
                        
                        <!-- Rejection Notice -->
                        <?php if ($is_rejected && !empty($agent_data['rejection_reason'])): ?>
                        <div class="rejection-notice">
                            <div class="rejection-title">
                                <i class="bi bi-exclamation-octagon-fill"></i>
                                Rejection Reason
                            </div>
                            <p class="rejection-message">"<?php echo htmlspecialchars($agent_data['rejection_reason']); ?>"</p>
                        </div>
                        <?php endif; ?>

                        <!-- About Section -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <i class="bi bi-person-lines-fill"></i>
                                About <?php echo htmlspecialchars($agent_data['first_name']); ?>
                            </h2>
                            <div class="agent-bio">
                                <?php echo htmlspecialchars($agent_data['bio'] ?? 'No biography provided.'); ?>
                            </div>
                        </div>

                        <!-- Professional Information -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <i class="bi bi-briefcase-fill"></i>
                                Professional Information
                            </h2>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="bi bi-patch-check-fill"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">License Number</div>
                                        <div class="info-value"><?php echo htmlspecialchars($agent_data['license_number'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="bi bi-clock-history"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Years of Experience</div>
                                        <div class="info-value"><?php echo htmlspecialchars($agent_data['years_experience'] ?? 'N/A'); ?> years</div>
                                    </div>
                                </div>
                                
                                
                                
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="bi bi-star-fill"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Specialization</div>
                                        <div class="info-value"><?php echo htmlspecialchars($agent_data['specialization'] ?? 'General Real Estate'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <i class="bi bi-telephone-fill"></i>
                                Contact Information
                            </h2>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="bi bi-envelope-fill"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Email Address</div>
                                        <div class="info-value"><?php echo htmlspecialchars($agent_data['email']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="bi bi-phone-fill"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Phone Number</div>
                                        <div class="info-value"><?php echo htmlspecialchars($agent_data['phone_number'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="bi bi-person-badge-fill"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Username</div>
                                        <div class="info-value"><?php echo htmlspecialchars($agent_data['username']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    
                    <div class="col-lg-4">
                        <div class="action-panel">
                            
                            <!-- Action Card -->
                            <div class="action-card">
                                <div class="action-title">
                                    <i class="bi bi-tools"></i>
                                    Admin Actions
                                </div>
                                
                                <?php if ($is_rejected): ?>
                                    <div class="text-center mb-4">
                                        <div class="alert alert-danger">
                                            <i class="bi bi-x-circle me-2"></i>
                                            This agent has been rejected and cannot be approved without reactivation.
                                        </div>
                                    </div>
                                    <div class="d-grid">
                                        <a href="agent.php" class="btn btn-modern btn-secondary-modern">
                                            <i class="bi bi-arrow-left me-2"></i>Back to Agent List
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <!-- Approve Agent Form -->
                                    <form id="approveAgentForm" class="agent-action-form" action="review_agent_details.php?account_id=<?php echo $account_id_to_review; ?>" method="POST">
                                        <input type="hidden" name="account_id" value="<?php echo $account_id_to_review; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <div class="d-grid gap-3">
                                            <button type="submit" 
                                                    class="btn btn-modern btn-approve btn-approve-agent" 
                                                    <?php echo ($is_profile_incomplete || $agent_data['is_approved']) ? 'disabled' : ''; ?>>
                                                <i class="bi bi-check-circle me-2"></i>Approve Agent
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <!-- Reject/Disable Agent Form (Modal Trigger) -->
                                    <form class="agent-action-form mt-3">
                                        <div class="d-grid gap-3">
                                            <?php if ($agent_data['is_approved']): ?>
                                                <!-- Disable Agent Button (for approved agents) -->
                                                <button type="button" 
                                                        class="btn btn-modern btn-reject btn-disable-agent" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#disableModal">
                                                    <i class="bi bi-ban me-2"></i>Disable Agent Account
                                                </button>
                                            <?php else: ?>
                                                <!-- Reject Agent Button (for pending agents) -->
                                                <button type="button" 
                                                        class="btn btn-modern btn-reject btn-reject-agent" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#rejectionModal" 
                                                        <?php echo $is_profile_incomplete ? 'disabled' : ''; ?>>
                                                    <i class="bi bi-x-circle me-2"></i>Reject Agent
                                                </button>
                                            <?php endif; ?>
                                            
                                            <a href="agent.php" class="btn btn-modern btn-secondary-modern btn-back-to-list">
                                                <i class="bi bi-arrow-left me-2"></i>Back to Agent List
                                            </a>
                                        </div>
                                    </form>
                                    
                                    <?php if ($is_profile_incomplete): ?>
                                        <div class="alert alert-info mt-3">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <small>Agent must complete their profile before approval/rejection actions become available.</small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($agent_data['is_approved']): ?>
                                        <div class="alert alert-success mt-3">
                                            <i class="bi bi-check-circle me-2"></i>
                                            <small>This agent has already been approved and is active in the system.</small>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Profile Completion Status -->
                            <div class="action-card">
                                <div class="action-title">
                                    <i class="bi bi-list-check"></i>
                                    Profile Status
                                </div>
                                
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-icon" style="background: <?php echo $agent_data['profile_completed'] ? 'var(--success-color)' : 'var(--warning-color)'; ?>">
                                            <i class="bi bi-<?php echo $agent_data['profile_completed'] ? 'check-lg' : 'clock'; ?>"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Profile</div>
                                            <div class="info-value"><?php echo $agent_data['profile_completed'] ? 'Complete' : 'Incomplete'; ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-icon" style="background: <?php echo $agent_data['is_active'] ? 'var(--success-color)' : 'var(--danger-color)'; ?>">
                                            <i class="bi bi-<?php echo $agent_data['is_active'] ? 'person-check' : 'person-x'; ?>"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Account Status</div>
                                            <div class="info-value"><?php echo $agent_data['is_active'] ? 'Active' : 'Inactive'; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="error-state">
            <div class="error-icon">
                <i class="bi bi-person-exclamation"></i>
            </div>
            <h2>Agent Not Found</h2>
            <p class="text-muted mb-4">The agent you are trying to review could not be found or may not have the correct role.</p>
            <a href="agent.php" class="btn btn-modern btn-secondary-modern">
                <i class="bi bi-arrow-left me-2"></i>Back to Agent List
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectionModal" tabindex="-1" aria-labelledby="rejectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="review_agent_details.php?account_id=<?php echo $account_id_to_review; ?>" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectionModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Reason for Rejection
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Please provide a detailed reason for rejecting this agent. This message will be sent to the agent's email and recorded in the system.</p>
                    
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="account_id" value="<?php echo $account_id_to_review; ?>">
                    
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Rejection Message *</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="4" 
                                  placeholder="Please provide specific reasons for rejection..." required></textarea>
                        <div class="form-text">Be specific and constructive in your feedback to help the agent understand what needs to be improved.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-modern btn-reject">
                        <i class="bi bi-send me-2"></i>Send Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Disable Agent Modal -->
<div class="modal fade" id="disableModal" tabindex="-1" aria-labelledby="disableModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="review_agent_details.php?account_id=<?php echo $account_id_to_review; ?>" method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                    <h5 class="modal-title" id="disableModalLabel">
                        <i class="bi bi-ban me-2"></i>Disable Agent Account
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Warning:</strong> This will deactivate the agent's account and prevent them from logging in. The agent will be notified via email.
                    </div>
                    
                    <p class="text-muted mb-3">Please provide a detailed reason for disabling this agent's account. This message will be sent to the agent's email and recorded in the system.</p>
                    
                    <input type="hidden" name="action" value="disable">
                    <input type="hidden" name="account_id" value="<?php echo $account_id_to_review; ?>">
                    
                    <div class="mb-3">
                        <label for="disable_reason" class="form-label">Reason for Disabling *</label>
                        <textarea class="form-control" id="disable_reason" name="disable_reason" rows="4" 
                                  placeholder="Please provide specific reasons for disabling this account..." required></textarea>
                        <div class="form-text">Be clear and professional in explaining why this account is being disabled.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-modern btn-reject">
                        <i class="bi bi-ban me-2"></i>Disable Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'logout_modal.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Enhanced form validation and submission handling for agent actions
    document.addEventListener('DOMContentLoaded', function() {
        let isFormSubmitting = false;
        
        // Handle Approve Agent Form specifically
        const approveForm = document.getElementById('approveAgentForm');
        if (approveForm) {
            console.log('Approve form found and listener attached');
            
            approveForm.addEventListener('submit', function(e) {
                console.log('Approve form submitted');
                console.log('Form action:', this.action);
                console.log('Form method:', this.method);
                
                // Log form data
                const formData = new FormData(this);
                console.log('Form data:');
                for (let [key, value] of formData.entries()) {
                    console.log(key + ': ' + value);
                }
                
                // Prevent double submission
                if (isFormSubmitting) {
                    console.log('Double submission prevented');
                    e.preventDefault();
                    return false;
                }
                
                const submitButton = this.querySelector('button[type="submit"]');
                
                if (submitButton && !submitButton.disabled) {
                    // Mark as submitting
                    isFormSubmitting = true;
                    console.log('Form marked as submitting');
                    
                    // Show loading state
                    const originalContent = submitButton.innerHTML;
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                    submitButton.disabled = true;
                    
                    // Re-enable after timeout (fallback)
                    setTimeout(() => {
                        submitButton.innerHTML = originalContent;
                        submitButton.disabled = false;
                        isFormSubmitting = false;
                        console.log('Form re-enabled after timeout');
                    }, 10000);
                }
                
                console.log('Form submitting normally...');
                // Allow form to submit normally
                return true;
            });
        }
        
        // Handle Rejection Modal Form
        const rejectionForm = document.querySelector('#rejectionModal form');
        if (rejectionForm) {
            rejectionForm.addEventListener('submit', function(e) {
                // Prevent double submission
                if (isFormSubmitting) {
                    e.preventDefault();
                    return false;
                }
                
                const requiredInputs = this.querySelectorAll('[required]');
                const submitButton = this.querySelector('button[type="submit"]');
                let isValid = true;

                // Validate required fields
                requiredInputs.forEach(input => {
                    if (!input.value.trim()) {
                        input.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        input.classList.remove('is-invalid');
                    }
                });

                if (!isValid) {
                    // Validation failed - prevent submission
                    e.preventDefault();
                    const firstInvalid = this.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                    }
                    return false;
                }
                
                // Validation passed - mark as submitting and show loading state
                isFormSubmitting = true;
                
                if (submitButton && !submitButton.disabled) {
                    const originalContent = submitButton.innerHTML;
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                    submitButton.disabled = true;
                    
                    // Re-enable after timeout (fallback in case redirect fails)
                    setTimeout(() => {
                        submitButton.innerHTML = originalContent;
                        submitButton.disabled = false;
                        isFormSubmitting = false;
                    }, 10000);
                }
                
                // Allow form to submit
                return true;
            });
        }

        // Real-time validation for textarea in rejection modal
        const rejectionTextarea = document.getElementById('rejection_reason');
        if (rejectionTextarea) {
            rejectionTextarea.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('is-invalid');
                }
            });
        }
    });

    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(alert => {
        if (!alert.classList.contains('alert-info')) { // Don't auto-hide info alerts
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }, 5000);
        }
    });

    // Intersection Observer for scroll animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Animate content sections on scroll
    document.querySelectorAll('.content-section').forEach(section => {
        section.style.opacity = '0';
        section.style.transform = 'translateY(20px)';
        section.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(section);
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + B to go back to agents
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            window.location.href = 'agent.php';
        }
        
        // Escape to close modals
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                const modal = bootstrap.Modal.getInstance(openModal);
                if (modal) modal.hide();
            }
        }
    });

    // Tooltip initialization
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Character count for textarea
    const rejectionTextarea = document.getElementById('rejection_reason');
    if (rejectionTextarea) {
        const maxLength = 500;
        const counter = document.createElement('div');
        counter.className = 'form-text text-end mt-1';
        counter.style.fontSize = '0.8rem';
        rejectionTextarea.parentNode.appendChild(counter);

        function updateCounter() {
            const remaining = maxLength - rejectionTextarea.value.length;
            counter.textContent = `${rejectionTextarea.value.length}/${maxLength} characters`;
            counter.style.color = remaining < 50 ? 'var(--danger-color)' : 'var(--text-muted)';
        }

        rejectionTextarea.addEventListener('input', updateCounter);
        rejectionTextarea.setAttribute('maxlength', maxLength);
        updateCounter();
    }
</script>

<!-- Footer -->
<footer class="py-4 mt-5" style="margin-left: 290px; background-color: var(--card-bg-color); border-top: 1px solid var(--border-color);">
    <div class="container-fluid px-4 text-center">
        <small class="text-muted">&copy; <?php echo date('Y'); ?> Prestige Properties. All Rights Reserved.</small>
    </div>
</footer>

</body>
</html>