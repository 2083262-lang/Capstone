<?php
/**
 * Payment card partial — included from agent_rental_payments.php
 * Expects $p (payment row) to be set by the calling loop.
 */
$badgeClass = strtolower($p['status']);
$imgSrc = !empty($p['property_image']) ? '../' . $p['property_image'] : null;
$commissionLabel = !empty($p['commission_amount']) ? '&#8369;' . number_format($p['commission_amount'], 2) . ' comm.' : '';
$rejectionNote = ($p['status'] === 'Rejected' && !empty($p['admin_notes'])) ? htmlspecialchars($p['admin_notes']) : '';
?>
<div class="payment-card"
     data-payment='<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>'>
    <div class="card-img-wrap">
        <?php if ($imgSrc): ?>
            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="Property" loading="lazy" onerror="this.src='../uploads/default-property.jpg'">
        <?php else: ?>
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--gray-500);"><i class="bi bi-image" style="font-size:2.5rem;"></i></div>
        <?php endif; ?>
        <div class="img-overlay"></div>
        <div class="type-badge"><i class="bi bi-cash-coin"></i> Rent Payment</div>
        <div class="status-badge <?= $badgeClass ?>">
            <i class="bi bi-circle-fill" style="font-size:0.35rem;"></i>
            <?= htmlspecialchars($p['status']) ?>
        </div>
        <div class="price-overlay">
            <div class="price">&#8369;<?= number_format($p['payment_amount'], 0) ?></div>
        </div>
    </div>

    <div class="card-body-content">
        <h3 class="prop-address" title="<?= htmlspecialchars($p['StreetAddress']) ?>"><?= htmlspecialchars($p['StreetAddress']) ?></h3>
        <div class="prop-location"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($p['City'] . ', ' . ($p['Province'] ?? '')) ?></div>

        <div class="payment-meta-row">
            <span class="payment-meta-item tenant-meta"><i class="bi bi-person"></i> <?= htmlspecialchars($p['tenant_name']) ?></span>
            <span class="payment-meta-item period-meta"><i class="bi bi-calendar-range"></i> <?= date('M d', strtotime($p['period_start'])) ?> &ndash; <?= date('M d', strtotime($p['period_end'])) ?></span>
            <span class="payment-meta-item date-meta"><i class="bi bi-calendar3"></i> <?= date('M d, Y', strtotime($p['payment_date'])) ?></span>
            <?php if ($commissionLabel): ?>
                <span class="payment-meta-item comm-meta"><i class="bi bi-coin"></i> <?= $commissionLabel ?></span>
            <?php endif; ?>
            <?php if (!empty($p['lease_status'])): ?>
                <span class="payment-meta-item lease-meta"><i class="bi bi-key"></i> <?= htmlspecialchars($p['lease_status']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($rejectionNote): ?>
        <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:3px;padding:0.35rem 0.6rem;margin-bottom:0.5rem;">
            <span style="font-size:0.72rem;color:#ef4444;font-weight:600;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Rejected:</span>
            <span style="font-size:0.72rem;color:var(--gray-400);"> <?= $rejectionNote ?></span>
        </div>
        <?php endif; ?>

        <div class="card-footer-section">
            <a href="rental_payments.php?property_id=<?= (int)$p['property_ID'] ?>" class="btn-manage">
                <i class="bi bi-key-fill"></i> Manage Lease
            </a>
        </div>
    </div>
</div>
