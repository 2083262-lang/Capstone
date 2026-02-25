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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Agent - <?php echo htmlspecialchars($agent_data['first_name'] ?? ''); ?> - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        /* ================================================
           ADMIN REVIEW AGENT DETAILS PAGE
           Structure matches property.php exactly:
           - Same :root, body, sidebar/content layout
           - Hardcoded margin-left: 290px (no variable overrides)
           - Prevents layout shift from sidebar
           ================================================ */

        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: #212529;
        }

        .admin-sidebar {
            background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%);
            color: #fff;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 290px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .admin-content {
            margin-left: 290px;
            padding: 2rem;
            min-height: 100vh;
            max-width: 1800px;
        }

        @media (max-width: 1200px) {
            .admin-content {
                margin-left: 0 !important;
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .admin-content {
                margin-left: 0 !important;
                padding: 1rem;
            }
        }

        /* ===== PAGE-SPECIFIC VARIABLES ===== */
        .admin-content {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --card-bg: #ffffff;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        /* ===== BREADCRUMB / BACK NAV ===== */
        .back-nav {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: var(--card-bg);
            transition: all 0.2s ease;
            margin-bottom: 1.5rem;
        }

        .back-nav:hover {
            color: var(--blue);
            border-color: var(--blue);
            background: rgba(37, 99, 235, 0.03);
            transform: translateX(-2px);
        }

        .back-nav i { font-size: 0.9rem; }

        /* ===== AGENT HERO HEADER ===== */
        .agent-hero {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .agent-hero::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
            z-index: 5;
        }

        .hero-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 2rem 2.5rem 1.5rem;
            position: relative;
        }

        .hero-banner::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: 
                radial-gradient(ellipse at top right, rgba(212, 175, 55, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(37, 99, 235, 0.06) 0%, transparent 50%);
            pointer-events: none;
        }

        .hero-banner::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            opacity: 0.4;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .hero-avatar {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--gold);
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
            flex-shrink: 0;
        }

        .hero-info {
            flex: 1;
            min-width: 0;
        }

        .hero-name {
            font-size: 1.75rem;
            font-weight: 800;
            color: #fff;
            margin: 0 0 0.25rem 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .hero-specialty {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.65);
            margin: 0 0 1rem 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .hero-stats {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .hero-stat {
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 4px;
            padding: 0.5rem 1rem;
            text-align: center;
            backdrop-filter: blur(8px);
            min-width: 100px;
        }

        .hero-stat-value {
            display: block;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--gold);
        }

        .hero-stat-label {
            font-size: 0.65rem;
            color: rgba(255,255,255,0.55);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .hero-status-badge {
            position: absolute;
            top: 2rem;
            right: 2.5rem;
            z-index: 3;
            padding: 0.4rem 1rem;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-approved { background: rgba(34, 197, 94, 0.9); color: #fff; }
        .status-pending { background: rgba(245, 158, 11, 0.9); color: #fff; }
        .status-rejected { background: rgba(239, 68, 68, 0.9); color: #fff; }
        .status-needs-profile { background: rgba(6, 182, 212, 0.9); color: #fff; }

        /* ===== CONTENT SECTIONS ===== */
        .content-section {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .content-section:hover::before { opacity: 1; }

        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .section-title i {
            color: var(--gold-dark);
            font-size: 1rem;
        }

        /* ===== INFO GRID ===== */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }

        .info-item:hover {
            border-color: rgba(37, 99, 235, 0.2);
            background: rgba(37, 99, 235, 0.02);
        }

        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08), rgba(212, 175, 55, 0.15));
            color: var(--gold-dark);
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .info-content { flex: 1; min-width: 0; }

        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            margin-bottom: 0.15rem;
        }

        .info-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-primary);
            word-break: break-word;
        }

        /* ===== BIO SECTION ===== */
        .agent-bio {
            font-size: 0.95rem;
            line-height: 1.8;
            color: #374151;
            white-space: pre-wrap;
            word-break: break-word;
            padding: 1.25rem;
            background: #f8fafc;
            border-radius: 4px;
            border-left: 3px solid var(--gold);
            text-align: justify;
        }

        /* ===== REJECTION NOTICE ===== */
        .rejection-notice {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.06) 0%, rgba(239, 68, 68, 0.03) 100%);
            border: 1px solid rgba(239, 68, 68, 0.15);
            border-left: 4px solid var(--danger);
            border-radius: 4px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .rejection-notice .rejection-title {
            color: #dc2626;
            font-weight: 700;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .rejection-notice .rejection-message {
            color: #991b1b;
            font-style: italic;
            font-size: 0.9rem;
            line-height: 1.6;
            margin: 0;
        }

        /* ===== ACTION PANEL (Sticky Sidebar) ===== */
        .action-panel {
            position: sticky;
            top: 90px;
            z-index: 10;
        }

        .action-card {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .action-card-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-primary);
            text-align: center;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .action-card-title i { color: var(--gold-dark); }

        /* ===== BUTTONS ===== */
        .btn-action {
            font-weight: 700;
            padding: 0.7rem 1.5rem;
            border-radius: 4px;
            border: none;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.8rem;
            position: relative;
            overflow: hidden;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-action::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-action:hover::before { left: 100%; }

        .btn-approve {
            background: linear-gradient(135deg, #16a34a 0%, var(--success) 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.25);
        }

        .btn-approve:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.35);
            color: #fff;
        }

        .btn-reject-action {
            background: linear-gradient(135deg, #dc2626 0%, var(--danger) 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }

        .btn-reject-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.35);
            color: #fff;
        }

        .btn-back {
            background: var(--card-bg);
            color: var(--text-secondary);
            border: 1px solid #e2e8f0;
            box-shadow: none;
        }

        .btn-back:hover {
            color: var(--text-primary);
            border-color: var(--blue);
            background: rgba(37, 99, 235, 0.03);
            transform: translateY(-1px);
        }

        .btn-action:disabled {
            opacity: 0.5;
            transform: none !important;
            cursor: not-allowed;
        }

        .btn-action:disabled::before { display: none; }

        /* Status indicators in action panel */
        .status-info-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            margin-bottom: 0.5rem;
        }

        .status-indicator {
            width: 36px;
            height: 36px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .status-indicator.green {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.2));
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .status-indicator.red {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.2));
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .status-indicator.amber {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.2));
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-info-text .status-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .status-info-text .status-value {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* ===== ALERTS ===== */
        .alert-custom {
            border: none;
            border-radius: 4px;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .alert-custom.alert-success {
            background: rgba(34, 197, 94, 0.06);
            color: #166534;
            border-left-color: var(--success);
        }

        .alert-custom.alert-danger {
            background: rgba(239, 68, 68, 0.06);
            color: #991b1b;
            border-left-color: var(--danger);
        }

        .alert-custom.alert-info {
            background: rgba(37, 99, 235, 0.06);
            color: #1e40af;
            border-left-color: var(--blue);
        }

        /* ===== MODALS ===== */
        .modal-content {
            border: none;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .modal-header-custom {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff;
            padding: 1.25rem 1.5rem;
            border: none;
            position: relative;
        }

        .modal-header-custom::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--blue));
        }

        .modal-header-custom .modal-title {
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-header-custom .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        .modal-header-custom .btn-close:hover { opacity: 1; }

        .modal-body { padding: 1.5rem; }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        /* ===== ERROR STATE ===== */
        .error-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
        }

        .error-state i {
            font-size: 3.5rem;
            color: rgba(37, 99, 235, 0.15);
            margin-bottom: 1.5rem;
            display: block;
        }

        .error-state h2 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .action-panel { position: relative; top: auto; }
            .hero-content { flex-direction: column; text-align: center; }
            .hero-stats { justify-content: center; }
            .hero-status-badge { position: relative; top: auto; right: auto; margin-bottom: 0.5rem; display: inline-block; }
        }

        @media (max-width: 768px) {
            .hero-banner { padding: 1.5rem; }
            .hero-name { font-size: 1.4rem; }
            .hero-avatar { width: 80px; height: 80px; }
            .info-grid { grid-template-columns: 1fr; }
            .hero-status-badge { position: relative; top: auto; right: auto; }
        }
    </style>
</head>
<body>

<?php
// Mark 'agent.php' as active in the sidebar since this page is part of the Agents workflow
$active_page = 'agent.php';
include 'admin_sidebar.php';
include 'admin_navbar.php';
?>

<div class="admin-content">

    <?php if ($error_message): ?>
        <div class="alert-custom alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_message; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success_message): ?>
        <div class="alert-custom alert-success" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if ($agent_data): ?>
        <?php
            $full_name = htmlspecialchars(trim($agent_data['first_name'] . ' ' . ($agent_data['middle_name'] ?? '') . ' ' . $agent_data['last_name']));
            
            $is_profile_incomplete = ($agent_data['profile_completed'] == 0);
            $is_rejected = (!$agent_data['is_active'] && !$agent_data['is_approved']);

            if ($is_profile_incomplete) {
                $status_text = 'Needs Profile';
                $status_class = 'status-needs-profile';
            } elseif ($is_rejected) {
                $status_text = 'Rejected';
                $status_class = 'status-rejected';
            } elseif (!$agent_data['is_approved']) {
                $status_text = 'Pending Approval';
                $status_class = 'status-pending';
            } else {
                $status_text = 'Approved';
                $status_class = 'status-approved';
            }

            // Profile image
            $profile_image = !empty($agent_data['profile_picture_url']) ? 
                htmlspecialchars($agent_data['profile_picture_url']) : 
                'https://ui-avatars.com/api/?name=' . urlencode($agent_data['first_name'] . '+' . $agent_data['last_name']) . '&background=d4af37&color=fff&size=220&font-size=0.4&bold=true';
        ?>

        <!-- Agent Hero Header -->
        <div class="agent-hero">
            <div class="hero-banner">
                <span class="hero-status-badge <?php echo $status_class; ?>">
                    <i class="bi bi-<?php echo $status_class === 'status-approved' ? 'check-circle-fill' : ($status_class === 'status-pending' ? 'clock-fill' : ($status_class === 'status-rejected' ? 'x-circle-fill' : 'exclamation-circle-fill')); ?> me-1"></i>
                    <?php echo $status_text; ?>
                </span>
                <div class="hero-content">
                    <img src="<?php echo $profile_image; ?>" 
                         alt="Agent Profile" class="hero-avatar"
                         onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($agent_data['first_name'] . '+' . $agent_data['last_name']); ?>&background=d4af37&color=fff&size=220&font-size=0.4&bold=true'">
                    <div class="hero-info">
                        <h1 class="hero-name"><?php echo $full_name; ?></h1>
                        <p class="hero-specialty"><?php echo htmlspecialchars($agent_data['specialization'] ?? 'Real Estate Agent'); ?></p>
                        <div class="hero-stats">
                            <div class="hero-stat">
                                <span class="hero-stat-value"><?php echo htmlspecialchars($agent_data['years_experience'] ?? '0'); ?></span>
                                <span class="hero-stat-label">Years Exp.</span>
                            </div>
                            <div class="hero-stat">
                                <span class="hero-stat-value"><?php echo date('M Y', strtotime($agent_data['date_registered'])); ?></span>
                                <span class="hero-stat-label">Registered</span>
                            </div>
                            <div class="hero-stat">
                                <span class="hero-stat-value"><?php echo htmlspecialchars($agent_data['license_number'] ?? 'N/A'); ?></span>
                                <span class="hero-stat-label">License No.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
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
            
            <!-- Action Sidebar -->
            <div class="col-lg-4">
                <div class="action-panel">
                    
                    <!-- Admin Actions Card -->
                    <div class="action-card">
                        <div class="action-card-title">
                            <i class="bi bi-tools"></i>
                            Admin Actions
                        </div>
                        
                        <?php if ($is_rejected): ?>
                            <div class="alert-custom alert-danger mb-3">
                                <i class="bi bi-x-circle me-1"></i>
                                <small>This agent has been rejected and cannot be approved without reactivation.</small>
                            </div>
                            <div class="d-grid">
                                <a href="agent.php" class="btn-action btn-back">
                                    <i class="bi bi-arrow-left"></i>Back to Agent List
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Approve Agent Form -->
                            <form id="approveAgentForm" action="review_agent_details.php?account_id=<?php echo $account_id_to_review; ?>" method="POST">
                                <input type="hidden" name="account_id" value="<?php echo $account_id_to_review; ?>">
                                <input type="hidden" name="action" value="approve">
                                <div class="d-grid gap-3">
                                    <button type="submit" class="btn-action btn-approve" 
                                            <?php echo ($is_profile_incomplete || $agent_data['is_approved']) ? 'disabled' : ''; ?>>
                                        <i class="bi bi-check-circle"></i>Approve Agent
                                    </button>
                                </div>
                            </form>
                            
                            <!-- Reject/Disable Button -->
                            <div class="d-grid gap-3 mt-3">
                                <?php if ($agent_data['is_approved']): ?>
                                    <button type="button" class="btn-action btn-reject-action" 
                                            data-bs-toggle="modal" data-bs-target="#disableModal">
                                        <i class="bi bi-ban"></i>Disable Agent Account
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn-action btn-reject-action" 
                                            data-bs-toggle="modal" data-bs-target="#rejectionModal"
                                            <?php echo $is_profile_incomplete ? 'disabled' : ''; ?>>
                                        <i class="bi bi-x-circle"></i>Reject Agent
                                    </button>
                                <?php endif; ?>
                                
                                <a href="agent.php" class="btn-action btn-back">
                                    <i class="bi bi-arrow-left"></i>Back to Agent List
                                </a>
                            </div>
                            
                            <?php if ($is_profile_incomplete): ?>
                                <div class="alert-custom alert-info mt-3" style="margin-bottom: 0;">
                                    <i class="bi bi-info-circle me-1"></i>
                                    <small>Agent must complete their profile before actions become available.</small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($agent_data['is_approved']): ?>
                                <div class="alert-custom alert-success mt-3" style="margin-bottom: 0;">
                                    <i class="bi bi-check-circle me-1"></i>
                                    <small>This agent is approved and active in the system.</small>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Profile Status Card -->
                    <div class="action-card">
                        <div class="action-card-title">
                            <i class="bi bi-list-check"></i>
                            Profile Status
                        </div>
                        
                        <div class="status-info-item">
                            <div class="status-indicator <?php echo $agent_data['profile_completed'] ? 'green' : 'amber'; ?>">
                                <i class="bi bi-<?php echo $agent_data['profile_completed'] ? 'check-lg' : 'clock'; ?>"></i>
                            </div>
                            <div class="status-info-text">
                                <div class="status-label">Profile</div>
                                <div class="status-value"><?php echo $agent_data['profile_completed'] ? 'Complete' : 'Incomplete'; ?></div>
                            </div>
                        </div>
                        
                        <div class="status-info-item">
                            <div class="status-indicator <?php echo $agent_data['is_active'] ? 'green' : 'red'; ?>">
                                <i class="bi bi-<?php echo $agent_data['is_active'] ? 'person-check' : 'person-x'; ?>"></i>
                            </div>
                            <div class="status-info-text">
                                <div class="status-label">Account</div>
                                <div class="status-value"><?php echo $agent_data['is_active'] ? 'Active' : 'Inactive'; ?></div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="error-state">
            <i class="bi bi-person-exclamation"></i>
            <h2>Agent Not Found</h2>
            <p class="text-muted mb-4">The agent you are trying to review could not be found or may not have the correct role.</p>
            <a href="agent.php" class="btn-action btn-back" style="width: auto; display: inline-flex;">
                <i class="bi bi-arrow-left"></i>Back to Agent List
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectionModal" tabindex="-1" aria-labelledby="rejectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="review_agent_details.php?account_id=<?php echo $account_id_to_review; ?>" method="POST">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="rejectionModalLabel">
                        <i class="bi bi-exclamation-triangle"></i>Reason for Rejection
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3" style="font-size: 0.85rem;">Please provide a detailed reason for rejecting this agent. This message will be sent to the agent's email.</p>
                    
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="account_id" value="<?php echo $account_id_to_review; ?>">
                    
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label" style="font-weight: 600; font-size: 0.85rem;">Rejection Message *</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="4" 
                                  placeholder="Please provide specific reasons for rejection..." required
                                  style="border-radius: 4px; border: 1px solid #e2e8f0; font-size: 0.9rem;"></textarea>
                        <div class="form-text" style="font-size: 0.75rem;">Be specific and constructive in your feedback.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" style="border-radius: 4px;">Cancel</button>
                    <button type="submit" class="btn-action btn-reject-action" style="width: auto; padding: 0.5rem 1.25rem;">
                        <i class="bi bi-send"></i>Send Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Disable Agent Modal -->
<div class="modal fade" id="disableModal" tabindex="-1" aria-labelledby="disableModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form action="review_agent_details.php?account_id=<?php echo $account_id_to_review; ?>" method="POST">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="disableModalLabel">
                        <i class="bi bi-ban"></i>Disable Agent Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="rejection-notice" style="margin-bottom: 1.25rem;">
                        <div class="rejection-title" style="color: #d97706;">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            Important Notice
                        </div>
                        <p class="rejection-message" style="color: #92400e; font-style: normal; font-size: 0.85rem;">
                            This action will deactivate the agent's account and prevent login access. The agent will be notified via email.
                        </p>
                    </div>
                    
                    <input type="hidden" name="action" value="disable">
                    <input type="hidden" name="account_id" value="<?php echo $account_id_to_review; ?>">
                    
                    <div class="mb-0">
                        <label for="disable_reason" class="form-label" style="font-weight: 600; font-size: 0.85rem;">Reason for Disabling <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="disable_reason" name="disable_reason" rows="4" 
                                  placeholder="Provide specific reasons for disabling this account..." required
                                  style="border-radius: 4px; border: 1px solid #e2e8f0; font-size: 0.9rem;"></textarea>
                        <div class="form-text" style="font-size: 0.75rem;">
                            <i class="bi bi-info-circle me-1"></i>Be clear and professional in your explanation.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" style="border-radius: 4px;">
                        <i class="bi bi-x-lg me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn-action btn-reject-action" style="width: auto; padding: 0.5rem 1.25rem;">
                        <i class="bi bi-ban"></i>Disable Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'logout_modal.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        let isFormSubmitting = false;
        
        // Handle Approve Agent Form
        const approveForm = document.getElementById('approveAgentForm');
        if (approveForm) {
            approveForm.addEventListener('submit', function(e) {
                if (isFormSubmitting) {
                    e.preventDefault();
                    return false;
                }
                
                const submitButton = this.querySelector('button[type="submit"]');
                if (submitButton && !submitButton.disabled) {
                    isFormSubmitting = true;
                    const originalContent = submitButton.innerHTML;
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                    submitButton.disabled = true;
                    
                    setTimeout(() => {
                        submitButton.innerHTML = originalContent;
                        submitButton.disabled = false;
                        isFormSubmitting = false;
                    }, 10000);
                }
                return true;
            });
        }
        
        // Handle Rejection Modal Form
        const rejectionForm = document.querySelector('#rejectionModal form');
        if (rejectionForm) {
            rejectionForm.addEventListener('submit', function(e) {
                if (isFormSubmitting) {
                    e.preventDefault();
                    return false;
                }
                
                const requiredInputs = this.querySelectorAll('[required]');
                const submitButton = this.querySelector('button[type="submit"]');
                let isValid = true;

                requiredInputs.forEach(input => {
                    if (!input.value.trim()) {
                        input.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        input.classList.remove('is-invalid');
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    const firstInvalid = this.querySelector('.is-invalid');
                    if (firstInvalid) firstInvalid.focus();
                    return false;
                }
                
                isFormSubmitting = true;
                if (submitButton && !submitButton.disabled) {
                    const originalContent = submitButton.innerHTML;
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                    submitButton.disabled = true;
                    setTimeout(() => {
                        submitButton.innerHTML = originalContent;
                        submitButton.disabled = false;
                        isFormSubmitting = false;
                    }, 10000);
                }
                return true;
            });
        }

        // Handle Disable Modal Form
        const disableForm = document.querySelector('#disableModal form');
        if (disableForm) {
            disableForm.addEventListener('submit', function(e) {
                if (isFormSubmitting) {
                    e.preventDefault();
                    return false;
                }
                
                const requiredInputs = this.querySelectorAll('[required]');
                const submitButton = this.querySelector('button[type="submit"]');
                let isValid = true;

                requiredInputs.forEach(input => {
                    if (!input.value.trim()) {
                        input.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        input.classList.remove('is-invalid');
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    const firstInvalid = this.querySelector('.is-invalid');
                    if (firstInvalid) firstInvalid.focus();
                    return false;
                }
                
                isFormSubmitting = true;
                if (submitButton && !submitButton.disabled) {
                    const originalContent = submitButton.innerHTML;
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                    submitButton.disabled = true;
                    setTimeout(() => {
                        submitButton.innerHTML = originalContent;
                        submitButton.disabled = false;
                        isFormSubmitting = false;
                    }, 10000);
                }
                return true;
            });
        }

        // Real-time validation for textareas
        document.querySelectorAll('textarea[required]').forEach(textarea => {
            textarea.addEventListener('input', function() {
                if (this.value.trim()) this.classList.remove('is-invalid');
            });
        });
    });

    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert-custom').forEach(alert => {
        if (!alert.classList.contains('alert-info')) {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                alert.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            window.location.href = 'agent.php';
        }
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
    tooltipTriggerList.map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    // Character count for rejection textarea
    const rejectionTextarea = document.getElementById('rejection_reason');
    if (rejectionTextarea) {
        const maxLength = 500;
        const counter = document.createElement('div');
        counter.className = 'form-text text-end mt-1';
        counter.style.fontSize = '0.75rem';
        rejectionTextarea.parentNode.appendChild(counter);

        function updateCounter() {
            const remaining = maxLength - rejectionTextarea.value.length;
            counter.textContent = rejectionTextarea.value.length + '/' + maxLength + ' characters';
            counter.style.color = remaining < 50 ? '#dc2626' : '#94a3b8';
        }

        rejectionTextarea.addEventListener('input', updateCounter);
        rejectionTextarea.setAttribute('maxlength', maxLength);
        updateCounter();
    }
</script>

</body>
</html>
