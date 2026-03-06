<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/config/session_timeout.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo '<div class="alert alert-danger">Unauthorized.</div>';
    exit;
}

$vid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($vid <= 0) {
    echo '<div class="alert alert-danger">Invalid verification ID.</div>';
    exit;
}

// Fetch verification
$stmt = $conn->prepare("
    SELECT rv.*,
           p.StreetAddress, p.City, p.Barangay, p.Province, p.PropertyType, p.Status AS property_status,
           a.first_name AS agent_first, a.last_name AS agent_last, a.email AS agent_email,
           ra.first_name AS reviewer_first, ra.last_name AS reviewer_last
    FROM rental_verifications rv
    JOIN property p ON rv.property_id = p.property_ID
    JOIN accounts a ON rv.agent_id = a.account_id
    LEFT JOIN accounts ra ON rv.reviewed_by = ra.account_id
    WHERE rv.verification_id = ?
");
$stmt->bind_param("i", $vid);
$stmt->execute();
$v = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$v) {
    echo '<div class="alert alert-danger">Verification not found.</div>';
    exit;
}

// Fetch documents
$doc_stmt = $conn->prepare("SELECT * FROM rental_verification_documents WHERE verification_id = ? ORDER BY uploaded_at");
$doc_stmt->bind_param("i", $vid);
$doc_stmt->execute();
$docs = $doc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$doc_stmt->close();

$badge_class = match($v['status']) { 'Pending' => 'bg-warning text-dark', 'Approved' => 'bg-success', 'Rejected' => 'bg-danger', default => 'bg-secondary' };
$lease_end = date('M d, Y', strtotime($v['lease_start_date'] . " + {$v['lease_term_months']} months - 1 day"));
?>

<div class="row g-3">
    <div class="col-12">
        <span class="badge <?= $badge_class ?> mb-2"><?= htmlspecialchars($v['status']) ?></span>
        <span class="text-muted ms-2">Submitted <?= date('M d, Y g:i A', strtotime($v['submitted_at'])) ?></span>
    </div>

    <div class="col-md-6">
        <h6 class="fw-bold text-muted text-uppercase small">Property</h6>
        <p class="mb-1 fw-semibold"><?= htmlspecialchars($v['StreetAddress']) ?></p>
        <p class="text-muted small mb-0"><?= htmlspecialchars($v['City'] . ', ' . $v['Barangay'] . ', ' . $v['Province']) ?></p>
        <p class="text-muted small">Type: <?= htmlspecialchars($v['PropertyType']) ?></p>
    </div>
    <div class="col-md-6">
        <h6 class="fw-bold text-muted text-uppercase small">Agent</h6>
        <p class="mb-1 fw-semibold"><?= htmlspecialchars($v['agent_first'] . ' ' . $v['agent_last']) ?></p>
        <p class="text-muted small"><?= htmlspecialchars($v['agent_email']) ?></p>
    </div>

    <div class="col-12"><hr class="my-1"></div>

    <div class="col-md-6">
        <h6 class="fw-bold text-muted text-uppercase small">Tenant Information</h6>
        <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($v['tenant_name']) ?></p>
        <?php if ($v['tenant_email']): ?>
            <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($v['tenant_email']) ?></p>
        <?php endif; ?>
        <?php if ($v['tenant_phone']): ?>
            <p class="mb-0"><strong>Phone:</strong> <?= htmlspecialchars($v['tenant_phone']) ?></p>
        <?php endif; ?>
    </div>
    <div class="col-md-6">
        <h6 class="fw-bold text-muted text-uppercase small">Lease Details</h6>
        <p class="mb-1"><strong>Monthly Rent:</strong> ₱<?= number_format($v['monthly_rent'], 2) ?></p>
        <p class="mb-1"><strong>Security Deposit:</strong> ₱<?= number_format($v['security_deposit'], 2) ?></p>
        <p class="mb-1"><strong>Lease Start:</strong> <?= date('M d, Y', strtotime($v['lease_start_date'])) ?></p>
        <p class="mb-1"><strong>Lease Term:</strong> <?= $v['lease_term_months'] ?> months</p>
        <p class="mb-0"><strong>Lease End (calc):</strong> <?= $lease_end ?></p>
    </div>

    <?php if ($v['additional_notes']): ?>
    <div class="col-12">
        <h6 class="fw-bold text-muted text-uppercase small">Additional Notes</h6>
        <p class="mb-0"><?= nl2br(htmlspecialchars($v['additional_notes'])) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($docs)): ?>
    <div class="col-12">
        <h6 class="fw-bold text-muted text-uppercase small">Supporting Documents (<?= count($docs) ?>)</h6>
        <div class="doc-list">
            <?php foreach ($docs as $d): ?>
            <div class="doc-item">
                <div>
                    <i class="bi bi-file-earmark me-1"></i>
                    <span class="small"><?= htmlspecialchars($d['original_filename']) ?></span>
                    <span class="text-muted small ms-1">(<?= round(($d['file_size'] ?? 0) / 1024) ?> KB)</span>
                </div>
                <a href="download_document.php?type=rental_verification&id=<?= $d['document_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Download">
                    <i class="bi bi-download"></i>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($v['status'] !== 'Pending'): ?>
    <div class="col-12">
        <h6 class="fw-bold text-muted text-uppercase small">Review Info</h6>
        <p class="mb-1"><strong>Reviewed by:</strong> <?= $v['reviewer_first'] ? htmlspecialchars($v['reviewer_first'] . ' ' . $v['reviewer_last']) : 'N/A' ?></p>
        <p class="mb-1"><strong>Reviewed at:</strong> <?= $v['reviewed_at'] ? date('M d, Y g:i A', strtotime($v['reviewed_at'])) : 'N/A' ?></p>
        <?php if ($v['admin_notes']): ?>
            <p class="mb-0"><strong>Admin Notes:</strong> <?= nl2br(htmlspecialchars($v['admin_notes'])) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
