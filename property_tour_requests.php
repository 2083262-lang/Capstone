<?php
session_start();
require_once 'connection.php';

// Admin-only access
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$property_id = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;
$error_message = '';
$property = null;
$tour_requests = [];
$status_counts = [
    'All' => 0,
    'Pending' => 0,
    'Confirmed' => 0,
    'Completed' => 0,
    'Cancelled' => 0,
    'Rejected' => 0,
    'Expired' => 0,
];

if ($property_id <= 0) {
    $error_message = 'Invalid property ID provided.';
} else {
    // Fetch property details and poster
    $prop_sql = "
        SELECT 
            p.property_ID, p.StreetAddress, p.City, p.Province, p.Status, p.approval_status,
            a.first_name AS poster_first_name, a.last_name AS poster_last_name,
            ur.role_name AS poster_role
        FROM property p
        LEFT JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
        LEFT JOIN accounts a ON a.account_id = pl.account_id
        LEFT JOIN user_roles ur ON ur.role_id = a.role_id
        WHERE p.property_ID = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($prop_sql);
    $stmt->bind_param('i', $property_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $error_message = 'Property not found.';
    } else {
        $property = $res->fetch_assoc();
    }
    $stmt->close();

    if ($property) {
        // Fetch tour requests for this property
        $tr_sql = "
            SELECT 
                tr.tour_id,
                tr.user_name,
                tr.user_email,
                tr.user_phone,
                tr.tour_date,
                tr.tour_time,
                tr.message,
                tr.request_status,
                tr.requested_at,
                tr.confirmed_at,
                tr.completed_at,
                tr.decision_reason,
                tr.decision_by,
                tr.decision_at,
                tr.expired_at,
                a.first_name AS agent_first_name,
                a.last_name AS agent_last_name
            FROM tour_requests tr
            LEFT JOIN accounts a ON a.account_id = tr.agent_account_id
            WHERE tr.property_id = ?
            ORDER BY 
                CASE tr.request_status
                    WHEN 'Pending' THEN 1
                    WHEN 'Confirmed' THEN 2
                    WHEN 'Completed' THEN 3
                    WHEN 'Cancelled' THEN 4
                    WHEN 'Rejected' THEN 5
                    WHEN 'Expired' THEN 6
                    ELSE 7
                END,
                tr.requested_at DESC
        ";
        $stmt = $conn->prepare($tr_sql);
        $stmt->bind_param('i', $property_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $status = $row['request_status'] ?: 'Pending';
            if (!isset($status_counts[$status])) { $status_counts[$status] = 0; }
            $status_counts[$status]++;
            $status_counts['All']++;

            // Format dates for display convenience
            $row['tour_date_fmt'] = $row['tour_date'] ? date('F j, Y', strtotime($row['tour_date'])) : '';
            $row['tour_time_fmt'] = $row['tour_time'] ? date('g:i A', strtotime($row['tour_time'])) : '';
            $row['requested_at_fmt'] = $row['requested_at'] ? date('F j, Y g:i A', strtotime($row['requested_at'])) : '';
            $row['confirmed_at_fmt'] = $row['confirmed_at'] ? date('F j, Y g:i A', strtotime($row['confirmed_at'])) : '';
            $row['completed_at_fmt'] = $row['completed_at'] ? date('F j, Y g:i A', strtotime($row['completed_at'])) : '';
            $row['decision_at_fmt'] = $row['decision_at'] ? date('F j, Y g:i A', strtotime($row['decision_at'])) : '';
            $tour_requests[] = $row;
        }
        $stmt->close();
    }
}

// Keep connection open for included components if needed

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tour Requests - Property #<?php echo htmlspecialchars((string)$property_id); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; }
        .content-wrapper { margin-left: 290px; padding: 2rem; }
        @media (max-width: 1200px) { .content-wrapper { margin-left: 0; padding: 1.5rem; } }
        @media (max-width: 768px) {
            .content-wrapper { margin-left: 0; padding: 1rem; }
            .d-flex.justify-content-between { flex-direction: column; align-items: flex-start !important; gap: 0.75rem; }
            .d-flex.flex-wrap.gap-2 { overflow-x: auto; flex-wrap: nowrap !important; -webkit-overflow-scrolling: touch; padding-bottom: 0.25rem; }
            .filter-btn { white-space: nowrap; flex-shrink: 0; font-size: 0.8rem; padding: 0.375rem 0.65rem; }
            .truncate { max-width: 200px; }
        }
        @media (max-width: 576px) {
            .content-wrapper { padding: 0.75rem; }
            h2 { font-size: 1.15rem; }
        }
        .card-shadow { box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #e9ecef; }
        .status-badge { font-weight: 700; font-size: .8rem; }
        .text-muted-2 { color: #6c757d; }
        .truncate { max-width: 420px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .table thead th { font-weight: 700; color: #495057; }
    </style>
    <script>
        function setFilter(status) {
            const rows = document.querySelectorAll('#requestsTable tbody tr');
            rows.forEach(r => {
                const s = r.getAttribute('data-status');
                r.style.display = (status === 'All' || s === status) ? '' : 'none';
            });
            // update active tab
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            const btn = document.querySelector(`[data-filter="${status}"]`);
            if (btn) btn.classList.add('active');
        }
        document.addEventListener('DOMContentLoaded', () => setFilter('All'));
    </script>
    <?php // Do not close PHP connection yet; included files might need it ?>
</head>
<body>
<?php 
    // Sidebar + Navbar
    $active_page = 'property.php';
    include 'admin_sidebar.php';
    include 'admin_navbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <?php if ($error_message): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error_message); ?></div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <h2 class="mb-1 fw-bold">Tour Requests</h2>
                    <div class="text-muted-2">
                        Property: <strong><?php echo htmlspecialchars($property['StreetAddress'] . ', ' . $property['City']); ?></strong>
                        <span class="ms-2">#<?php echo (int)$property['property_ID']; ?></span>
                    </div>
                    <?php if (!empty($property['poster_first_name'])): ?>
                        <div class="text-muted-2 small mt-1">Listed by: <?php echo htmlspecialchars($property['poster_first_name'] . ' ' . $property['poster_last_name']); ?> (<?php echo htmlspecialchars($property['poster_role'] ?? ''); ?>)</div>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (['All','Pending','Confirmed','Completed','Cancelled','Rejected','Expired'] as $st): ?>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="<?php echo $st; ?>" onclick="setFilter('<?php echo $st; ?>')">
                            <?php echo $st; ?>
                            <span class="badge bg-secondary ms-1"><?php echo (int)($status_counts[$st] ?? 0); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card card-shadow">
                <div class="card-body">
                    <?php if (empty($tour_requests)): ?>
                        <p class="text-muted mb-0">No tour requests yet for this property.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle" id="requestsTable">
                                <thead>
                                    <tr>
                                        <th>Requested</th>
                                        <th>Client</th>
                                        <th>Contact</th>
                                        <th>Preferred</th>
                                        <th>Status</th>
                                        <th>Message</th>
                                        <th>Agent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tour_requests as $r): ?>
                                        <tr data-status="<?php echo htmlspecialchars($r['request_status']); ?>">
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($r['requested_at_fmt']); ?></div>
                                                <?php if (!empty($r['confirmed_at_fmt']) && $r['request_status']==='Confirmed'): ?>
                                                    <div class="small text-muted">Confirmed: <?php echo htmlspecialchars($r['confirmed_at_fmt']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($r['completed_at_fmt']) && $r['request_status']==='Completed'): ?>
                                                    <div class="small text-muted">Completed: <?php echo htmlspecialchars($r['completed_at_fmt']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($r['decision_at_fmt']) && in_array($r['request_status'], ['Cancelled','Rejected'])): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($r['request_status']); ?>: <?php echo htmlspecialchars($r['decision_at_fmt']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($r['request_status']==='Expired' && !empty($r['expired_at'])): ?>
                                                    <div class="small text-muted">Expired: <?php echo date('F j, Y g:i A', strtotime($r['expired_at'])); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($r['user_name']); ?></div>
                                                <div class="small text-muted">#<?php echo (int)$r['tour_id']; ?></div>
                                            </td>
                                            <td>
                                                <div><a href="mailto:<?php echo htmlspecialchars($r['user_email']); ?>"><?php echo htmlspecialchars($r['user_email']); ?></a></div>
                                                <?php if (!empty($r['user_phone'])): ?>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($r['user_phone']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($r['tour_date_fmt']); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($r['tour_time_fmt']); ?></div>
                                            </td>
                                            <td>
                                                <?php 
                                                    $badge = 'bg-secondary';
                                                    switch ($r['request_status']) {
                                                        case 'Pending': $badge = 'bg-warning text-dark'; break;
                                                        case 'Confirmed': $badge = 'bg-primary'; break;
                                                        case 'Completed': $badge = 'bg-success'; break;
                                                        case 'Cancelled': $badge = 'bg-dark'; break;
                                                        case 'Rejected': $badge = 'bg-danger'; break;
                                                        case 'Expired': $badge = 'bg-secondary'; break;
                                                    }
                                                ?>
                                                <span class="badge <?php echo $badge; ?> status-badge"><?php echo htmlspecialchars($r['request_status']); ?></span>
                                            </td>
                                            <td class="truncate" title="<?php echo htmlspecialchars($r['message']); ?>">
                                                <?php echo htmlspecialchars($r['message'] ?: '—'); ?>
                                                <?php if (!empty($r['decision_reason']) && in_array($r['request_status'], ['Cancelled','Rejected','Expired'])): ?>
                                                    <div class="small text-muted mt-1">Reason: <?php echo htmlspecialchars($r['decision_reason']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($r['agent_first_name'])): ?>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($r['agent_first_name'] . ' ' . $r['agent_last_name']); ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
