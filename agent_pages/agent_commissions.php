<?php
session_start();
require_once __DIR__ . '/../connection.php';

// Agent-only access
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header('Location: ../login.php');
    exit;
}

$agent_id = (int)$_SESSION['account_id'];

// Fetch commissions for this agent
$sql = "
    SELECT 
        ac.commission_id, ac.sale_id, ac.agent_id,
        ac.commission_amount, ac.commission_percentage, ac.status,
        ac.calculated_at, ac.paid_at, ac.payment_reference, ac.notes,
        fs.property_id, fs.final_sale_price, fs.sale_date,
        p.StreetAddress, p.City, p.PropertyType
    FROM agent_commissions ac
    JOIN finalized_sales fs ON fs.sale_id = ac.sale_id
    LEFT JOIN property p ON p.property_ID = fs.property_id
    WHERE ac.agent_id = ?
    ORDER BY ac.calculated_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $agent_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
$totalCalculated = 0.0;
$totalPaid = 0.0;
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
    $totalCalculated += (float)$r['commission_amount'];
    if (strcasecmp($r['status'], 'paid') === 0) {
        $totalPaid += (float)$r['commission_amount'];
    }
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Commissions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background: #111; color: #eee; }
        .page-header { background: linear-gradient(135deg, #161209 0%, #2a2318 100%); color: #fff; border-radius: 12px; padding: 1.5rem; margin: 1rem 0 1.5rem; }
        .stat { background: #1a1a1a; border: 1px solid #2b2b2b; border-radius: 12px; padding: 1rem; }
        .stat h6 { color: #c9ab52; text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
        .stat .val { font-size: 1.6rem; font-weight: 800; color: #fff; }
        .card { background: #121212; border: 1px solid #2a2a2a; }
        .table thead th { color: #c9ab52; border-bottom-color: #2f2f2f; }
        .table tbody td { color: #ddd; border-top-color: #212121; }
        .badge-calculated { background: #2d2d2d; color: #eecf6d; }
        .badge-paid { background: #16381e; color: #63d285; }
        .muted { color: #9aa0a6; }
    </style>
</head>
<body>
<?php include __DIR__ . '/agent_navbar.php'; ?>
<div class="container-fluid mt-3">
    <div class="page-header">
        <h3 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Your Commissions</h3>
        <div class="muted">Track calculated and paid commissions</div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat">
                <h6>Total Calculated</h6>
                <div class="val">₱<?php echo number_format($totalCalculated, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat">
                <h6>Total Paid</h6>
                <div class="val">₱<?php echo number_format($totalPaid, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat">
                <h6>Unpaid Balance</h6>
                <div class="val">₱<?php echo number_format(max($totalCalculated - $totalPaid, 0), 2); ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Property</th>
                            <th>Sale Date</th>
                            <th>Sale Price</th>
                            <th>Commission %</th>
                            <th>Commission</th>
                            <th>Status</th>
                            <th>Paid At</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="9" class="text-center muted py-4">No commissions yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $i => $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$r['commission_id']); ?></td>
                                    <td>
                                        <?php 
                                            $address = trim(($r['StreetAddress'] ?? '') . ', ' . ($r['City'] ?? ''));
                                            $address = $address !== ',' ? $address : 'Property #' . (int)$r['property_id'];
                                        ?>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($address); ?></div>
                                        <div class="muted small"><?php echo htmlspecialchars($r['PropertyType'] ?? ''); ?></div>
                                    </td>
                                    <td><?php echo $r['sale_date'] ? date('M j, Y', strtotime($r['sale_date'])) : '—'; ?></td>
                                    <td>₱<?php echo number_format((float)$r['final_sale_price'], 2); ?></td>
                                    <td><?php echo number_format((float)$r['commission_percentage'], 2); ?>%</td>
                                    <td class="fw-bold">₱<?php echo number_format((float)$r['commission_amount'], 2); ?></td>
                                    <td>
                                        <?php if (strcasecmp($r['status'], 'paid') === 0): ?>
                                            <span class="badge badge-paid">Paid</span>
                                        <?php else: ?>
                                            <span class="badge badge-calculated">Calculated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $r['paid_at'] ? date('M j, Y', strtotime($r['paid_at'])) : '—'; ?></td>
                                    <td class="text-truncate" style="max-width: 160px;" title="<?php echo htmlspecialchars($r['payment_reference'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($r['payment_reference'] ?? '—'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
