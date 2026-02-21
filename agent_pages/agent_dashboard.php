<?php
session_start(); 
include '../connection.php';

// Check if the user is logged in AND their role is 'agent'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: login.php");
    exit(); 
}

$agent_account_id = $_SESSION['account_id'];
$agent_username = $_SESSION['username'];

// Fetch agent information
$agent_info_query = "SELECT ai.*, a.first_name, a.last_name, a.email, a.phone_number 
                     FROM agent_information ai 
                     JOIN accounts a ON ai.account_id = a.account_id 
                     WHERE ai.account_id = ?";
$stmt = $conn->prepare($agent_info_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$agent_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch statistics
$stats_query = "SELECT 
                COUNT(CASE WHEN approval_status = 'approved' THEN 1 END) as total_active,
                COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) as total_pending,
                COUNT(CASE WHEN Status = 'For Sale' AND approval_status = 'approved' THEN 1 END) as for_sale,
                COUNT(CASE WHEN Status = 'For Rent' AND approval_status = 'approved' THEN 1 END) as for_rent,
                SUM(CASE WHEN approval_status = 'approved' THEN ViewsCount ELSE 0 END) as total_views,
                SUM(CASE WHEN approval_status = 'approved' THEN Likes ELSE 0 END) as total_likes
                FROM property 
                WHERE property_ID IN (
                    SELECT property_id FROM property_log WHERE account_id = ? AND action = 'CREATED'
                )";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch recent properties
$recent_properties_query = "SELECT p.*, 
                           (SELECT PhotoURL FROM property_images WHERE property_ID = p.property_ID ORDER BY SortOrder ASC LIMIT 1) as image 
                           FROM property p 
                           WHERE p.property_ID IN (
                               SELECT property_id FROM property_log WHERE account_id = ? AND action = 'CREATED'
                           ) 
                           ORDER BY p.ListingDate DESC 
                           LIMIT 5";
$stmt = $conn->prepare($recent_properties_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$recent_properties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch recent activity logs
$activity_query = "SELECT pl.*, p.StreetAddress, p.City 
                  FROM property_log pl 
                  JOIN property p ON pl.property_id = p.property_ID 
                  WHERE pl.account_id = ? 
                  ORDER BY pl.log_timestamp DESC 
                  LIMIT 8";
$stmt = $conn->prepare($activity_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$recent_activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Dashboard - Real Estate</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

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
            --shadow-heavy: 0 8px 32px rgba(0,0,0,0.15);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background-color);
            margin: 0;
        }
        /* Content */
        .content {
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Welcome Section */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-color), #2a2419);
            color: white;
            padding: 2.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-medium);
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(188, 158, 66, 0.2), transparent);
            border-radius: 50%;
        }

        .welcome-content {
            position: relative;
            z-index: 2;
        }

        .welcome-banner h1 {
            font-weight: 700;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .welcome-banner p {
            opacity: 0.9;
            font-size: 1.1rem;
            margin-bottom: 0;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg-color);
            border-radius: 16px;
            padding: 1.75rem;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--secondary-color);
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .stat-card:hover::before {
            transform: scaleY(1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-icon.primary {
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.2));
            color: var(--secondary-color);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.2));
            color: #28a745;
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 193, 7, 0.2));
            color: #ffc107;
        }

        .stat-icon.info {
            background: linear-gradient(135deg, rgba(13, 202, 240, 0.1), rgba(13, 202, 240, 0.2));
            color: #0dcaf0;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Section Cards */
        .section-card {
            background: var(--card-bg-color);
            border-radius: 16px;
            padding: 1.75rem;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--secondary-color);
        }

        .view-all-btn {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .view-all-btn:hover {
            color: var(--primary-color);
            transform: translateX(3px);
        }

        /* Property Cards */
        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .property-card {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            background: var(--card-bg-color);
        }

        .property-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .property-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: linear-gradient(135deg, #f0ebe5, #e8dfd2);
        }

        .property-body {
            padding: 1.25rem;
        }

        .property-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .property-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .property-location {
            color: var(--text-muted);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .property-status {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-approved {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
        }

        /* Activity Timeline */
        .activity-timeline {
            position: relative;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
            z-index: 2;
        }

        .activity-icon.created {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .activity-icon.updated {
            background: rgba(13, 202, 240, 0.1);
            color: #0dcaf0;
        }

        .activity-icon.deleted {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .activity-content {
            flex: 1;
            background: rgba(188, 158, 66, 0.05);
            padding: 1rem;
            border-radius: 10px;
            border-left: 3px solid var(--secondary-color);
        }

        .activity-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .activity-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, var(--secondary-color), #d4b966);
            color: var(--primary-color);
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-light);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
            color: var(--primary-color);
        }

        .action-btn i {
            font-size: 1.5rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content {
                padding: 1rem;
            }

            .welcome-banner {
                padding: 1.5rem;
            }

            .welcome-banner h1 {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 1rem;
            }

            .stat-card {
                padding: 1.25rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<?php include 'agent_navbar.php'; ?>

<div class="content">
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="welcome-content">
            <h1>Welcome back, <?php echo htmlspecialchars($agent_info['first_name'] ?? $agent_username); ?></h1>
            <p>Here's an overview of your real estate portfolio and recent activities</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary">
                <i class="fas fa-building"></i>
            </div>
            <div class="stat-value"><?php echo $stats['total_active'] ?? 0; ?></div>
            <div class="stat-label">Active Properties</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-value"><?php echo $stats['total_pending'] ?? 0; ?></div>
            <div class="stat-label">Pending Approval</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">
                <i class="fas fa-home"></i>
            </div>
            <div class="stat-value"><?php echo $stats['for_sale'] ?? 0; ?></div>
            <div class="stat-label">For Sale</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon info">
                <i class="fas fa-key"></i>
            </div>
            <div class="stat-value"><?php echo $stats['for_rent'] ?? 0; ?></div>
            <div class="stat-label">For Rent</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon primary">
                <i class="fas fa-eye"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_views'] ?? 0); ?></div>
            <div class="stat-label">Total Views</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">
                <i class="fas fa-heart"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_likes'] ?? 0); ?></div>
            <div class="stat-label">Total Likes</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="section-card">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-bolt"></i>
                Quick Actions
            </h2>
        </div>
        <div class="quick-actions">
            <a href="add_property.php" class="action-btn">
                <i class="fas fa-plus-circle"></i>
                <span>Add Property</span>
            </a>
            <a href="agent_property.php" class="action-btn">
                <i class="fas fa-list"></i>
                <span>View All Properties</span>
            </a>
            <a href="agent_profile.php" class="action-btn">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </a>
            <a href="agent_reports.php" class="action-btn">
                <i class="fas fa-chart-bar"></i>
                <span>View Reports</span>
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Recent Properties -->
        <div class="col-lg-7 mb-4">
            <div class="section-card">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-home"></i>
                        Recent Properties
                    </h2>
                    <a href="agent_property.php" class="view-all-btn">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>

                <?php if (empty($recent_properties)): ?>
                    <div class="empty-state">
                        <i class="fas fa-home"></i>
                        <p>No properties yet. Add your first property to get started!</p>
                        <a href="add_property.php" class="btn btn-primary mt-3">
                            <i class="fas fa-plus me-2"></i>Add Property
                        </a>
                    </div>
                <?php else: ?>
                    <div class="property-grid">
                        <?php foreach ($recent_properties as $property): ?>
                            <div class="property-card">
                                <img src="../<?php echo htmlspecialchars($property['image'] ?? 'https://via.placeholder.com/300x180'); ?>" 
                                     alt="Property" class="property-image">
                                <div class="property-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="property-price">
                                            ₱<?php echo number_format($property['ListingPrice'], 0); ?>
                                        </div>
                                        <span class="property-status status-<?php echo $property['approval_status']; ?>">
                                            <?php echo ucfirst($property['approval_status']); ?>
                                        </span>
                                    </div>
                                    <div class="property-title"><?php echo htmlspecialchars($property['PropertyType']); ?></div>
                                    <div class="property-location">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?php echo htmlspecialchars($property['City']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-lg-5">
            <div class="section-card">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-history"></i>
                        Recent Activity
                    </h2>
                </div>

                <?php if (empty($recent_activity)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No recent activity to display</p>
                    </div>
                <?php else: ?>
                    <div class="activity-timeline">
                        <?php foreach ($recent_activity as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo strtolower($activity['action']); ?>">
                                    <i class="fas fa-<?php echo $activity['action'] === 'CREATED' ? 'plus' : ($activity['action'] === 'UPDATED' ? 'edit' : 'trash'); ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php echo ucfirst(strtolower($activity['action'])); ?> Property
                                    </div>
                                    <div class="activity-meta">
                                        <?php echo htmlspecialchars($activity['StreetAddress']) . ', ' . htmlspecialchars($activity['City']); ?>
                                        <br>
                                        <small><?php echo date('M d, Y g:i A', strtotime($activity['log_timestamp'])); ?></small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'logout_agent_modal.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Animate stats on load
    const statValues = document.querySelectorAll('.stat-value');
    statValues.forEach(stat => {
        const target = parseInt(stat.textContent.replace(/,/g, ''));
        let current = 0;
        const increment = target / 50;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                stat.textContent = target.toLocaleString();
                clearInterval(timer);
            } else {
                stat.textContent = Math.floor(current).toLocaleString();
            }
        }, 20);
    });

    // Add animation to cards on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    document.querySelectorAll('.stat-card, .property-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(card);
    });
});
</script>
</body>
</html>