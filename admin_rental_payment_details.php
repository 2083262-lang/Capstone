<?php
session_start();
require_once __DIR__ . '/config/session_timeout.php';
include 'connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo '<div class="alert alert-danger">Unauthorized.</div>';
    exit();
}

if (!isset($_GET['payment_id'])) {
    echo '<div class="alert alert-danger">Missing payment ID.</div>';
    exit();
}

$payment_id = (int) $_GET['payment_id'];

// Fetch payment details
$stmt = $conn->prepare("
    SELECT rp.*, 
           fr.tenant_name, fr.monthly_rent, fr.commission_rate,
           p.StreetAddress, p.City,
           a.first_name AS agent_first, a.last_name AS agent_last
    FROM rental_payments rp
    JOIN finalized_rentals fr ON rp.rental_id = fr.rental_id
    JOIN property p ON fr.property_id = p.property_ID
    JOIN accounts a ON rp.agent_id = a.account_id
    WHERE rp.payment_id = ?
");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();

if (!$payment) {
    echo '<div class="alert alert-danger">Payment not found.</div>';
    exit();
}

// Fetch documents
$doc_stmt = $conn->prepare("SELECT * FROM rental_payment_documents WHERE payment_id = ? ORDER BY uploaded_at DESC");
$doc_stmt->bind_param("i", $payment_id);
$doc_stmt->execute();
$docs = $doc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!-- ===== Payment Details Section ===== -->
<div class="pd-section">
    <div class="pd-section-title"><i class="bi bi-info-circle-fill"></i> Payment Details</div>
    <div class="pd-info-grid">
        <div class="pd-info-item pd-full">
            <div class="pd-info-label">Property</div>
            <div class="pd-info-value"><?= htmlspecialchars($payment['StreetAddress'] . ', ' . $payment['City']) ?></div>
        </div>
        <div class="pd-info-item">
            <div class="pd-info-label">Agent</div>
            <div class="pd-info-value"><i class="bi bi-person-badge me-1" style="color:#b8941f;font-size:.8rem;"></i><?= htmlspecialchars($payment['agent_first'] . ' ' . $payment['agent_last']) ?></div>
        </div>
        <div class="pd-info-item">
            <div class="pd-info-label">Tenant</div>
            <div class="pd-info-value"><i class="bi bi-person me-1" style="color:#2563eb;font-size:.8rem;"></i><?= htmlspecialchars($payment['tenant_name']) ?></div>
        </div>
        <div class="pd-info-item">
            <div class="pd-info-label">Payment Amount</div>
            <div class="pd-info-value" style="font-size:1.05rem;color:#16a34a;">&#8369;<?= number_format($payment['payment_amount'], 2) ?></div>
        </div>
        <div class="pd-info-item">
            <div class="pd-info-label">Payment Date</div>
            <div class="pd-info-value"><?= date('F d, Y', strtotime($payment['payment_date'])) ?></div>
        </div>
        <div class="pd-info-item">
            <div class="pd-info-label">Period Covered</div>
            <div class="pd-info-value"><?= date('M d, Y', strtotime($payment['period_start'])) ?> &ndash; <?= date('M d, Y', strtotime($payment['period_end'])) ?></div>
        </div>
        <div class="pd-info-item">
            <div class="pd-info-label">Status</div>
            <div class="pd-info-value">
                <?php
                $sc = match($payment['status']) {
                    'Pending'   => ['pd-status-pending',   'bi-clock-history'],
                    'Confirmed' => ['pd-status-confirmed', 'bi-check-circle-fill'],
                    'Rejected'  => ['pd-status-rejected',  'bi-x-octagon-fill'],
                    default     => ['', 'bi-circle'],
                };
                ?>
                <span class="pd-status-pill <?= $sc[0] ?>"><i class="bi <?= $sc[1] ?>"></i><?= $payment['status'] ?></span>
            </div>
        </div>
        <div class="pd-info-item">
            <div class="pd-info-label">Submitted</div>
            <div class="pd-info-value"><?= date('M d, Y g:i A', strtotime($payment['submitted_at'])) ?></div>
        </div>
        <?php if ($payment['additional_notes']): ?>
        <div class="pd-info-item pd-full">
            <div class="pd-info-label"><i class="bi bi-chat-left-text me-1"></i>Agent Notes</div>
            <div class="pd-info-value" style="font-size:.85rem;font-weight:400;"><?= nl2br(htmlspecialchars($payment['additional_notes'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($payment['admin_notes']): ?>
        <div class="pd-info-item pd-full" style="border-color:rgba(37,99,235,.25);background:rgba(37,99,235,.02);">
            <div class="pd-info-label" style="color:#2563eb;"><i class="bi bi-shield-check me-1"></i>Admin Notes</div>
            <div class="pd-info-value" style="font-size:.85rem;font-weight:400;"><?= nl2br(htmlspecialchars($payment['admin_notes'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== Documents Section ===== -->
<div class="pd-section">
    <div class="pd-section-title"><i class="bi bi-paperclip"></i> Proof Documents <span style="font-size:.65rem;font-weight:600;background:rgba(212,175,55,.12);color:#b8941f;border:1px solid rgba(212,175,55,.2);padding:.15rem .5rem;border-radius:10px;margin-left:.4rem;"><?= count($docs) ?></span></div>
    <?php if (!empty($docs)): ?>
    <div class="pd-docs-list">
        <?php foreach ($docs as $doc):
            $ext = strtolower(pathinfo($doc['original_filename'], PATHINFO_EXTENSION));
            [$iconClass, $iconName] = match(true) {
                $ext === 'pdf'                                 => ['pdf', 'bi-file-earmark-pdf-fill'],
                in_array($ext, ['jpg','jpeg','png','gif','webp']) => ['img', 'bi-file-earmark-image-fill'],
                in_array($ext, ['doc','docx'])                 => ['doc', 'bi-file-earmark-word-fill'],
                default                                        => ['gen', 'bi-file-earmark-fill'],
            };
        ?>
        <a href="download_rental_payment_doc.php?doc_id=<?= (int)$doc['document_id'] ?>" class="pd-doc-item" target="_blank">
            <div class="pd-doc-icon <?= $iconClass ?>"><i class="bi <?= $iconName ?>"></i></div>
            <div class="pd-doc-info">
                <div class="pd-doc-name"><?= htmlspecialchars($doc['original_filename']) ?></div>
                <div class="pd-doc-date"><i class="bi bi-calendar3"></i><?= date('M d, Y g:i A', strtotime($doc['uploaded_at'])) ?></div>
            </div>
            <i class="bi bi-cloud-arrow-down pd-doc-dl"></i>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="pd-empty">
        <i class="bi bi-file-earmark-x"></i>
        <p>No documents were uploaded for this payment.</p>
    </div>
    <?php endif; ?>
</div>
