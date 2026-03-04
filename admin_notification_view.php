<?php
session_start();
require_once 'connection.php';
require_once __DIR__ . '/config/paths.php';

// Check if admin is logged in
$is_admin = false;
if (isset($_SESSION['account_id'])) {
    if (isset($_SESSION['role_id']) && intval($_SESSION['role_id']) === 1) {
        $is_admin = true;
    }
    if (isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin') {
        $is_admin = true;
    }
}
if (!$is_admin) {
    header("Location: login.php");
    exit();
}

$notification_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($notification_id <= 0) {
    header("Location: admin_notifications.php");
    exit();
}

// Check if new columns exist
$has_new_cols = false;
$col_check = $conn->query("SHOW COLUMNS FROM notifications LIKE 'title'");
if ($col_check && $col_check->num_rows > 0) $has_new_cols = true;

// Fetch notification
if ($has_new_cols) {
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE notification_id = ?");
} else {
    $stmt = $conn->prepare("SELECT notification_id, item_id, item_type, message, created_at, is_read FROM notifications WHERE notification_id = ?");
}
$stmt->bind_param("i", $notification_id);
$stmt->execute();
$result = $stmt->get_result();
$notif = $result->fetch_assoc();
$stmt->close();

if (!$notif) {
    header("Location: admin_notifications.php");
    exit();
}

// Mark as read
if (!isset($notif['is_read']) || (int)$notif['is_read'] === 0) {
    $upd = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ?");
    $upd->bind_param("i", $notification_id);
    $upd->execute();
    $upd->close();
    $notif['is_read'] = 1;
}

// Fill defaults
if (!$has_new_cols) {
    $notif['title'] = ''; $notif['category'] = 'update'; $notif['priority'] = 'normal';
    $notif['action_url'] = null; $notif['icon'] = null;
}
$item_type = $notif['item_type'] ?? 'general';
if (empty($notif['title'])) {
    switch ($item_type) {
        case 'agent':        $notif['title'] = 'Agent Profile Submission'; break;
        case 'tour':         $notif['title'] = 'New Tour Request'; break;
        case 'property':     $notif['title'] = 'Property Update'; break;
        case 'property_sale':$notif['title'] = 'Sale Verification'; break;
        default:             $notif['title'] = 'Notification'; break;
    }
}
if (empty($notif['icon'])) {
    switch ($item_type) {
        case 'agent':        $notif['icon'] = 'bi-person-badge'; break;
        case 'tour':         $notif['icon'] = 'bi-calendar-check'; break;
        case 'property':     $notif['icon'] = 'bi-building'; break;
        case 'property_sale':$notif['icon'] = 'bi-cash-stack'; break;
        default:             $notif['icon'] = 'bi-bell'; break;
    }
}
if (empty($notif['action_url'])) {
    switch ($item_type) {
        case 'agent':        $notif['action_url'] = 'review_agent_details.php?id=' . $notif['item_id']; break;
        case 'tour':         $notif['action_url'] = 'admin_tour_request_details.php?id=' . $notif['item_id']; break;
        case 'property':     $notif['action_url'] = 'view_property.php?id=' . $notif['item_id']; break;
        case 'property_sale':$notif['action_url'] = 'admin_property_sale_approvals.php'; break;
        default:             $notif['action_url'] = '#'; break;
    }
}

// Fetch related context
$related_info = [];
$view_admin_id = (int)$_SESSION['account_id'];
switch ($item_type) {
    case 'agent':
        $r = $conn->query("SELECT a.first_name, a.last_name, a.email, ai.license_number, ai.years_experience, ai.bio, ai.is_approved 
            FROM accounts a LEFT JOIN agent_information ai ON a.account_id = ai.account_id 
            WHERE a.account_id = " . (int)$notif['item_id']);
        if ($r && $row = $r->fetch_assoc()) $related_info = $row;
        break;
    case 'tour':
        // Security: verify tour is for a property managed by this admin
        $tour_auth = $conn->query("SELECT tr.tour_id, tr.property_id, tr.user_name, tr.user_email, tr.tour_date, tr.tour_time, tr.tour_type, tr.request_status, tr.message,
            p.StreetAddress, p.City, p.PropertyType 
            FROM tour_requests tr 
            LEFT JOIN property p ON tr.property_id = p.property_ID 
            JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' AND pl.account_id = $view_admin_id
            WHERE tr.tour_id = " . (int)$notif['item_id']);
        if ($tour_auth && $row = $tour_auth->fetch_assoc()) {
            // Mask sensitive requester contact info
            if (!empty($row['user_email'])) {
                $parts = explode('@', $row['user_email']);
                $row['user_email'] = substr($parts[0], 0, 2) . str_repeat('*', max(0, strlen($parts[0]) - 2)) . '@' . ($parts[1] ?? '');
            }
            // Do NOT include user_phone in related info (privacy)
            $related_info = $row;
        } else {
            // Admin is not authorized to see this tour's details
            header("Location: admin_notifications.php");
            exit();
        }
        break;
    case 'property':
        $r = $conn->query("SELECT p.*, pi.PhotoURL FROM property p 
            LEFT JOIN property_images pi ON p.property_ID = pi.property_ID AND pi.SortOrder = 1
            WHERE p.property_ID = " . (int)$notif['item_id']);
        if ($r && $row = $r->fetch_assoc()) $related_info = $row;
        break;
    case 'property_sale':
        $r = $conn->query("SELECT sv.*, p.StreetAddress, p.City FROM sale_verifications sv 
            LEFT JOIN property p ON sv.property_id = p.property_ID 
            WHERE sv.verification_id = " . (int)$notif['item_id']);
        if ($r && $row = $r->fetch_assoc()) $related_info = $row;
        break;
}

// Time formatting
function view_time_ago($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 172800) return 'Yesterday at ' . date('g:i A', strtotime($dt));
    return date('M d, Y \a\t g:i A', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($notif['title']); ?> - Notifications</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    <style>
        :root {
            --primary-color: #161209; --secondary-color: #bc9e42;
            --accent-color: #a08636; --bg-light: #f8f9fa; --border-color: #e0e0e0;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-light); color: #212529; }
        .admin-sidebar { background: linear-gradient(180deg, #161209, #1f1a0f); color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; z-index: 1000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .admin-content { margin-left: 290px; padding: 2rem; min-height: 100vh; max-width: 1800px; --gold: #d4af37; --gold-light: #f4d03f; --gold-dark: #b8941f; --blue: #2563eb; --blue-light: #3b82f6; --blue-dark: #1e40af; --card-bg: #fff; --text-primary: #212529; --text-secondary: #6c757d; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; padding: 1.5rem; } }
        @media (max-width: 768px) { .admin-content { margin-left: 0 !important; padding: 1rem; } }

        .page-header { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header::before { content: ''; position: absolute; top:0;left:0;right:0;bottom:0; background: radial-gradient(ellipse at top right, rgba(37,99,235,0.04), transparent 50%), radial-gradient(ellipse at bottom left, rgba(212,175,55,0.03), transparent 50%); pointer-events: none; }
        .page-header::after { content: ''; position: absolute; top:0;left:0;right:0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; }
        .page-header .subtitle { color: var(--text-secondary); font-size: 0.95rem; }

        .btn-gold { background: linear-gradient(135deg, var(--gold-dark), var(--gold) 50%, var(--gold-dark)); color: #fff; border: none; padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 700; border-radius: 4px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(212,175,55,0.25); }
        .btn-gold:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,175,55,0.35); color: #fff; }
        .btn-outline-admin { background: var(--card-bg); color: var(--text-secondary); border: 1px solid #e2e8f0; padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 600; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; cursor: pointer; text-decoration: none; }
        .btn-outline-admin:hover { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.03); }

        .detail-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; position: relative; overflow: hidden; margin-bottom: 1.5rem; }
        .detail-card::before { content: ''; position: absolute; top:0;left:0;right:0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); z-index: 5; }
        .detail-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 1rem; background: linear-gradient(180deg, #fafbfc, var(--card-bg)); }
        .detail-icon { width: 48px; height: 48px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
        .detail-icon.agent { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .detail-icon.tour { background: rgba(6,182,212,0.08); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .detail-icon.property { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .detail-icon.property_sale, .detail-icon.sale { background: rgba(212,175,55,0.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.15); }
        .detail-card-body { padding: 1.5rem; }
        .detail-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 0.25rem; }
        .detail-value { font-size: 0.92rem; color: var(--text-primary); font-weight: 500; margin-bottom: 1rem; }
        .detail-value:last-child { margin-bottom: 0; }

        .type-tag { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; border-radius: 2px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .type-tag.agent { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.12); }
        .type-tag.tour { background: rgba(6,182,212,0.08); color: #0891b2; border: 1px solid rgba(6,182,212,0.12); }
        .type-tag.property { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.12); }
        .type-tag.property_sale { background: rgba(212,175,55,0.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.12); }

        .priority-tag { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.15rem 0.5rem; border-radius: 2px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; }
        .priority-tag.urgent { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }
        .priority-tag.high { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .priority-tag.normal { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.12); }
        .priority-tag.low { background: rgba(100,116,139,0.08); color: #64748b; border: 1px solid rgba(100,116,139,0.12); }

        .context-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
        .context-item { padding: 0.75rem 1rem; background: #fafbfc; border: 1px solid #e2e8f0; border-radius: 4px; }
        .context-item .ctx-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 0.15rem; }
        .context-item .ctx-value { font-size: 0.9rem; font-weight: 600; color: var(--text-primary); }
    </style>
</head>
<body>
    <?php include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1><i class="bi bi-bell" style="color: var(--gold-dark);"></i> Notification Detail</h1>
                    <div class="subtitle">Viewing notification #<?php echo $notification_id; ?></div>
                </div>
                <div class="d-flex gap-2">
                    <a href="admin_notifications.php" class="btn-outline-admin"><i class="bi bi-arrow-left"></i> Back to All</a>
                    <?php if (!empty($notif['action_url']) && $notif['action_url'] !== '#'): ?>
                    <a href="<?php echo htmlspecialchars($notif['action_url']); ?>" class="btn-gold"><i class="bi bi-arrow-right"></i> Go to Source</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Notification Detail Card -->
        <div class="detail-card">
            <div class="detail-card-header">
                <div class="detail-icon <?php echo htmlspecialchars($item_type); ?>">
                    <i class="bi <?php echo htmlspecialchars($notif['icon']); ?>"></i>
                </div>
                <div style="flex:1;">
                    <div style="font-size:1.15rem;font-weight:800;color:var(--text-primary);"><?php echo htmlspecialchars($notif['title']); ?></div>
                    <div style="font-size:0.82rem;color:var(--text-secondary);">
                        <?php echo view_time_ago($notif['created_at']); ?>
                        &middot; <?php echo date('M d, Y \a\t g:i A', strtotime($notif['created_at'])); ?>
                    </div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <span class="type-tag <?php echo htmlspecialchars($item_type); ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $item_type)); ?>
                    </span>
                    <span class="priority-tag <?php echo htmlspecialchars($notif['priority'] ?? 'normal'); ?>">
                        <?php echo ucfirst($notif['priority'] ?? 'normal'); ?>
                    </span>
                </div>
            </div>
            <div class="detail-card-body">
                <div class="detail-label">Message</div>
                <div class="detail-value" style="font-size:1rem;line-height:1.7;">
                    <?php echo htmlspecialchars($notif['message']); ?>
                </div>

                <?php if (!empty($notif['category'])): ?>
                <div class="detail-label">Category</div>
                <div class="detail-value"><?php echo ucfirst($notif['category']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Related Context -->
        <?php if (!empty($related_info)): ?>
        <div class="detail-card">
            <div class="detail-card-header">
                <div class="detail-icon <?php echo htmlspecialchars($item_type); ?>">
                    <i class="bi bi-info-circle"></i>
                </div>
                <div style="font-size:1.05rem;font-weight:700;color:var(--text-primary);">Related Information</div>
            </div>
            <div class="detail-card-body">
                <div class="context-grid">
                    <?php if ($item_type === 'agent'): ?>
                        <div class="context-item">
                            <div class="ctx-label">Agent Name</div>
                            <div class="ctx-value"><?php echo htmlspecialchars(($related_info['first_name'] ?? '') . ' ' . ($related_info['last_name'] ?? '')); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Email</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['email'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">License #</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['license_number'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Experience</div>
                            <div class="ctx-value"><?php echo htmlspecialchars(($related_info['years_experience'] ?? '0') . ' years'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Approval Status</div>
                            <div class="ctx-value"><?php echo (isset($related_info['is_approved']) && $related_info['is_approved']) ? '<span style="color:#16a34a;">Approved</span>' : '<span style="color:#d97706;">Pending</span>'; ?></div>
                        </div>

                    <?php elseif ($item_type === 'tour'): ?>
                        <div class="context-item">
                            <div class="ctx-label">Requester</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['user_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Contact</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['user_email'] ?? 'N/A'); ?> <small class="text-muted">(masked)</small></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Property</div>
                            <div class="ctx-value"><?php echo htmlspecialchars(($related_info['StreetAddress'] ?? '') . ', ' . ($related_info['City'] ?? '')); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Tour Date</div>
                            <div class="ctx-value"><?php echo !empty($related_info['tour_date']) ? date('M d, Y', strtotime($related_info['tour_date'])) : 'N/A'; ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Tour Time</div>
                            <div class="ctx-value"><?php echo !empty($related_info['tour_time']) ? date('g:i A', strtotime($related_info['tour_time'])) : 'N/A'; ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Status</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['request_status'] ?? 'N/A'); ?></div>
                        </div>

                    <?php elseif ($item_type === 'property'): ?>
                        <div class="context-item">
                            <div class="ctx-label">Address</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['StreetAddress'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">City</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['City'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Type</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['PropertyType'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Price</div>
                            <div class="ctx-value">&#8369;<?php echo number_format($related_info['ListingPrice'] ?? 0, 2); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Status</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['Status'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Approval</div>
                            <div class="ctx-value"><?php echo ucfirst($related_info['approval_status'] ?? 'N/A'); ?></div>
                        </div>

                    <?php elseif ($item_type === 'property_sale'): ?>
                        <div class="context-item">
                            <div class="ctx-label">Property</div>
                            <div class="ctx-value"><?php echo htmlspecialchars(($related_info['StreetAddress'] ?? '') . ', ' . ($related_info['City'] ?? '')); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Sale Price</div>
                            <div class="ctx-value">&#8369;<?php echo number_format($related_info['sale_price'] ?? 0, 2); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Buyer</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['buyer_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Sale Date</div>
                            <div class="ctx-value"><?php echo !empty($related_info['sale_date']) ? date('M d, Y', strtotime($related_info['sale_date'])) : 'N/A'; ?></div>
                        </div>
                        <div class="context-item">
                            <div class="ctx-label">Verification Status</div>
                            <div class="ctx-value"><?php echo htmlspecialchars($related_info['status'] ?? 'N/A'); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($notif['action_url']) && $notif['action_url'] !== '#'): ?>
                <div style="margin-top:1.25rem; padding-top:1rem; border-top:1px solid #e2e8f0;">
                    <a href="<?php echo htmlspecialchars($notif['action_url']); ?>" class="btn-gold">
                        <i class="bi bi-arrow-right"></i> View Full Details
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>
