<?php
session_start();
include 'connection.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/config/paths.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$admin_id = (int) $_SESSION['account_id'];

// Fetch all rental payments with property + agent + commission info + property image
$sql = "
    SELECT rp.*, 
           fr.tenant_name, fr.monthly_rent AS lease_rent, fr.commission_rate,
           p.StreetAddress, p.City, p.Barangay, p.Province, p.PropertyType,
           a.first_name AS agent_first, a.last_name AS agent_last,
           rc.commission_amount, rc.status AS commission_status,
           (SELECT pi.PhotoURL FROM property_images pi WHERE pi.property_ID = p.property_ID ORDER BY pi.SortOrder ASC LIMIT 1) AS property_image
    FROM rental_payments rp
    JOIN finalized_rentals fr ON rp.rental_id = fr.rental_id
    JOIN property p ON fr.property_id = p.property_ID
    JOIN accounts a ON rp.agent_id = a.account_id
    LEFT JOIN rental_commissions rc ON rp.payment_id = rc.payment_id
    ORDER BY rp.submitted_at DESC
";
$result = $conn->query($sql);
$payments = [];
while ($row = $result->fetch_assoc()) $payments[] = $row;

$pending   = array_filter($payments, fn($p) => $p['status'] === 'Pending');
$confirmed = array_filter($payments, fn($p) => $p['status'] === 'Confirmed');
$rejected  = array_filter($payments, fn($p) => $p['status'] === 'Rejected');

$total_confirmed_revenue = array_sum(array_map(fn($p) => $p['status'] === 'Confirmed' ? (float)$p['payment_amount'] : 0, $payments));

$status_counts = [
    'All'       => count($payments),
    'Pending'   => count($pending),
    'Confirmed' => count($confirmed),
    'Rejected'  => count($rejected),
];

$status_tabs = [
    'All'       => ['icon' => 'bi-layers',       'count' => $status_counts['All']],
    'Pending'   => ['icon' => 'bi-clock-history', 'count' => $status_counts['Pending']],
    'Confirmed' => ['icon' => 'bi-check-circle',  'count' => $status_counts['Confirmed']],
    'Rejected'  => ['icon' => 'bi-x-circle',      'count' => $status_counts['Rejected']],
];

$active_status = $_GET['status'] ?? 'All';
if (!array_key_exists($active_status, $status_tabs)) $active_status = 'All';

$success_message = '';
$error_message   = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'confirmed': $success_message = 'Payment has been confirmed and commission recorded.'; break;
        case 'rejected':  $success_message = 'Payment has been rejected.'; break;
    }
}
if (isset($_GET['error'])) {
    $error_message = match($_GET['error']) {
        'confirm_failed' => 'Failed to confirm payment.',
        'reject_failed'  => 'Failed to reject payment.',
        default          => 'An error occurred.',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rental Payments - Admin Panel</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    <style>
        /* ===== GLOBAL LAYOUT ===== */
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
            --gold: #d4af37; --gold-light: #f4d03f; --gold-dark: #b8941f;
            --blue: #2563eb; --blue-light: #3b82f6; --blue-dark: #1e40af;
            --card-bg: #ffffff; --text-primary: #212529; --text-secondary: #6c757d;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-light); color: #212529; }
        .admin-sidebar { background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%); color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; z-index: 1000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .admin-content { margin-left: 290px; padding: 2rem; min-height: 100vh; max-width: 1800px; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; padding: 1.5rem; } }
        @media (max-width: 768px)  { .admin-content { margin-left: 0 !important; padding: 1rem; } }

        /* ===== PAGE HEADER ===== */
        .page-header { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(ellipse at top right, rgba(37,99,235,0.04) 0%, transparent 50%), radial-gradient(ellipse at bottom left, rgba(212,175,55,0.03) 0%, transparent 50%); pointer-events: none; }
        .page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; }
        .page-header .subtitle { font-size: 0.95rem; color: var(--text-secondary); font-weight: 400; }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem; cursor: default; transition: all 0.2s ease; position: relative; overflow: hidden; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, rgba(212,175,55,0.03), rgba(37,99,235,0.02)); opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card .kpi-icon { width: 48px; height: 48px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
        .kpi-icon.gold  { background: rgba(212,175,55,0.1);  color: var(--gold); }
        .kpi-icon.amber { background: rgba(245,158,11,0.1);  color: #d97706; }
        .kpi-icon.green { background: rgba(34,197,94,0.1);   color: #16a34a; }
        .kpi-icon.red   { background: rgba(239,68,68,0.1);   color: #dc2626; }
        .kpi-icon.blue  { background: rgba(37,99,235,0.1);   color: var(--blue); }
        .kpi-card .kpi-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.125rem; }
        .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); }

        /* ===== STATUS TABS ===== */
        .payment-tabs { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; position: relative; }
        .payment-tabs::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .payment-tabs .nav-tabs { border: none; padding: 0 1rem; margin: 0; }
        .payment-tabs .nav-item { margin: 0; }
        .payment-tabs .nav-link { border: none; border-radius: 0; padding: 1rem 1.25rem; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); background: transparent; transition: all 0.2s ease; display: flex; align-items: center; gap: 0.5rem; border-bottom: 2px solid transparent; }
        .payment-tabs .nav-link:hover { color: var(--text-primary); background: rgba(37,99,235,0.03); }
        .payment-tabs .nav-link.active { color: var(--gold-dark); border-bottom-color: var(--gold); background: rgba(212,175,55,0.04); }
        .tab-badge { font-size: 0.7rem; padding: 0.15rem 0.5rem; border-radius: 10px; font-weight: 700; }
        .badge-all       { background: rgba(212,175,55,0.1); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.15); }
        .badge-pending   { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .badge-confirmed { background: rgba(34,197,94,0.1);  color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .badge-rejected  { background: rgba(239,68,68,0.1);  color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }

        /* ===== ACTION BAR ===== */
        .action-bar { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 0.85rem 1.25rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; position: relative; overflow: hidden; }
        .action-bar::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .action-bar-left { display: flex; align-items: center; gap: 0.85rem; flex: 1; min-width: 0; }
        .action-bar-right { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
        .action-search-wrap { position: relative; flex: 1; }
        .action-search-wrap input { width: 100%; padding: 0.5rem 1rem 0.5rem 2.35rem; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.85rem; color: var(--text-primary); background: #f8fafc; transition: all 0.2s; }
        .action-search-wrap input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; background: #fff; }
        .action-search-wrap input::placeholder { color: #94a3b8; }
        .action-search-wrap .ab-search-icon { position: absolute; left: 0.72rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.83rem; pointer-events: none; }
        .btn-outline-admin { background: var(--card-bg); color: var(--text-secondary); border: 1px solid #e2e8f0; padding: 0.5rem 1rem; font-size: 0.82rem; font-weight: 600; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; cursor: pointer; }
        .btn-outline-admin:hover { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.03); }
        .btn-outline-admin.filter-active { border-color: var(--gold); color: var(--gold-dark); background: rgba(212,175,55,0.04); }
        .filter-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 5px; background: var(--blue); color: #fff; border-radius: 10px; font-size: 0.7rem; font-weight: 700; }

        /* ===== FILTER SIDEBAR ===== */
        .sf-sidebar { position: fixed; top: 0; right: 0; width: 100%; height: 100%; z-index: 10050; pointer-events: none; }
        .sf-sidebar.active { pointer-events: all; }
        .sf-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.4); opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .sf-sidebar.active .sf-overlay { opacity: 1; pointer-events: all; }
        .sf-content { position: absolute; top: 0; right: 0; width: 480px; max-width: 92vw; height: 100%; background: #fff; border-left: 1px solid rgba(37,99,235,0.15); box-shadow: -8px 0 32px rgba(15,23,42,0.1); transform: translateX(100%); transition: transform 0.25s ease; display: flex; flex-direction: column; overflow: hidden; }
        .sf-sidebar.active .sf-content { transform: translateX(0); }
        .sf-header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; padding: 1.25rem 1.5rem; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; flex-shrink: 0; }
        .sf-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); }
        .sf-header h4 { font-weight: 700; font-size: 1.05rem; display: flex; align-items: center; gap: 0.6rem; margin: 0; }
        .sf-header h4 i { color: var(--gold); }
        .sf-header-right { display: flex; align-items: center; gap: 0.6rem; }
        .sf-active-pill { display: none; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; background: rgba(212,175,55,0.15); color: var(--gold); border: 1px solid rgba(212,175,55,0.25); border-radius: 10px; font-size: 0.72rem; font-weight: 700; }
        .sf-active-pill.show { display: inline-flex; }
        .btn-close-sf { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff; width: 34px; height: 34px; border-radius: 4px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; font-size: 0.9rem; flex-shrink: 0; }
        .btn-close-sf:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); }
        .sf-results-bar { background: rgba(37,99,235,0.04); border-bottom: 1px solid rgba(37,99,235,0.1); padding: 0.7rem 1.5rem; display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
        .sf-results-bar i { color: var(--blue); font-size: 0.95rem; }
        .sf-results-num { font-size: 1.1rem; font-weight: 800; color: var(--blue); }
        .sf-results-label { font-size: 0.78rem; color: var(--text-secondary); }
        .sf-body { flex: 1; overflow-y: auto; padding: 1.1rem; background: #f8fafc; }
        .sf-body::-webkit-scrollbar { width: 4px; }
        .sf-body::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.3); border-radius: 4px; }
        .sf-section { background: #fff; border-radius: 4px; padding: 1rem 1.1rem; margin-bottom: 0.75rem; border: 1px solid #e2e8f0; }
        .sf-section:last-child { margin-bottom: 0; }
        .sf-section-title { font-weight: 700; font-size: 0.73rem; color: var(--text-primary); margin-bottom: 0.8rem; padding-bottom: 0.5rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 0.45rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .sf-section-title i { color: var(--gold); font-size: 0.85rem; }
        .sf-search-wrap { position: relative; }
        .sf-search-wrap input { width: 100%; padding: 0.6rem 0.85rem 0.6rem 2.35rem; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.85rem; color: var(--text-primary); transition: all 0.2s; }
        .sf-search-wrap input::placeholder { color: #94a3b8; }
        .sf-search-wrap input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .sf-search-wrap > i { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; pointer-events: none; }
        .price-range-inputs { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0.6rem; align-items: center; }
        .price-input { position: relative; }
        .price-input input { width: 100%; padding: 0.55rem 0.65rem 0.55rem 1.7rem; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.82rem; font-weight: 600; color: var(--text-primary); transition: all 0.2s; }
        .price-input input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .price-input .currency-sym { position: absolute; left: 0.5rem; top: 50%; transform: translateY(-50%); color: var(--gold-dark); font-weight: 700; font-size: 0.76rem; pointer-events: none; }
        .range-divider { color: #94a3b8; font-weight: 600; text-align: center; font-size: 0.9rem; }
        .quick-filters { display: flex; gap: 0.4rem; margin-top: 0.55rem; flex-wrap: wrap; }
        .quick-filter-btn { padding: 0.32rem 0.72rem; border: 1px solid #e2e8f0; background: #fff; border-radius: 2px; font-size: 0.73rem; font-weight: 500; cursor: pointer; transition: all 0.2s; color: var(--text-primary); }
        .quick-filter-btn:hover { border-color: var(--gold); background: #fffbeb; }
        .quick-filter-btn.active { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; border-color: var(--gold-dark); font-weight: 600; }
        .sf-select { width: 100%; padding: 0.55rem 0.8rem; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.84rem; font-weight: 500; color: var(--text-primary); transition: all 0.2s; }
        .sf-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .date-range-inputs { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0.55rem; align-items: center; }
        .date-range-inputs input { width: 100%; padding: 0.52rem 0.65rem; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.8rem; font-weight: 500; color: var(--text-primary); min-width: 0; transition: all 0.2s; }
        .date-range-inputs input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .sf-footer { padding: 1rem 1.1rem; background: #fff; border-top: 1px solid #e2e8f0; display: flex; gap: 0.55rem; flex-shrink: 0; }
        .sf-footer .btn { flex: 1; padding: 0.62rem 1rem; font-weight: 600; border-radius: 4px; font-size: 0.83rem; transition: all 0.2s; cursor: pointer; border: none; }
        .sf-footer .btn-reset { background: #fff; border: 1px solid #e2e8f0 !important; color: var(--text-secondary); }
        .sf-footer .btn-reset:hover { border-color: rgba(239,68,68,0.3) !important; color: #dc2626; background: rgba(239,68,68,0.03); }
        .sf-footer .btn-apply { background: linear-gradient(135deg, var(--blue-dark, #1e40af), var(--blue)); color: #fff; }
        .sf-footer .btn-apply:hover { box-shadow: 0 4px 12px rgba(37,99,235,0.25); }

        /* ===== CONTENT AREA ===== */
        .tab-content { padding: 1.5rem; }
        .payments-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }

        /* ===== PAYMENT CARD ===== */
        .payment-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; transition: all 0.3s ease; height: 100%; display: flex; flex-direction: column; position: relative; }
        .payment-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); opacity: 0; transition: opacity 0.3s ease; z-index: 5; }
        .payment-card:hover { border-color: rgba(37,99,235,0.25); box-shadow: 0 8px 32px rgba(37,99,235,0.08); transform: translateY(-4px); }
        .payment-card:hover::before { opacity: 1; }

        .card-img-wrap { position: relative; height: 180px; background: #f1f5f9; overflow: hidden; }
        .card-img-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .payment-card:hover .card-img-wrap img { transform: scale(1.05); }
        .card-img-wrap .img-overlay { position: absolute; bottom: 0; left: 0; right: 0; height: 60%; background: linear-gradient(to top, rgba(0,0,0,0.65) 0%, transparent 100%); pointer-events: none; }

        .card-img-wrap .type-badge { position: absolute; bottom: 12px; left: 14px; padding: 0.2rem 0.6rem; border-radius: 2px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 3; background: rgba(0,0,0,0.7); color: #e2e8f0; backdrop-filter: blur(4px); display: inline-flex; align-items: center; gap: 0.3rem; }
        .card-img-wrap .status-badge { position: absolute; top: 12px; right: 12px; display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.65rem; border-radius: 2px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 3; }
        .status-badge.pending   { background: rgba(245,158,11,0.9); color: #fff; }
        .status-badge.confirmed { background: rgba(34,197,94,0.9);  color: #fff; }
        .status-badge.rejected  { background: rgba(239,68,68,0.9);  color: #fff; }

        .card-img-wrap .price-overlay { position: absolute; bottom: 12px; right: 14px; z-index: 3; }
        .card-img-wrap .price-overlay .price { font-size: 1.3rem; font-weight: 800; background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.5)); }

        .payment-card .card-body-content { padding: 1rem 1.25rem; flex: 1; display: flex; flex-direction: column; position: relative; z-index: 2; }
        .payment-card .prop-address { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
        .payment-card .prop-location { font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-bottom: 0.75rem; }
        .payment-card .prop-location i { color: var(--blue); font-size: 0.75rem; }

        .payment-meta-row { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
        .payment-meta-item { display: inline-flex; align-items: center; gap: 0.3rem; background: #f8fafc; padding: 0.2rem 0.55rem; border-radius: 2px; border: 1px solid #e2e8f0; font-size: 0.75rem; font-weight: 500; color: var(--text-secondary); }
        .payment-meta-item i { color: #94a3b8; font-size: 0.7rem; }
        .payment-meta-item.agent-meta i { color: var(--blue); }
        .payment-meta-item.tenant-meta i { color: var(--gold-dark); }
        .payment-meta-item.date-meta i { color: var(--gold-dark); }
        .payment-meta-item.comm-meta i { color: #16a34a; }
        .payment-meta-item.period-meta i { color: var(--blue); }

        .payment-card .card-footer-section { margin-top: auto; padding-top: 0.75rem; border-top: 1px solid #e2e8f0; }
        .payment-card .btn-manage { display: flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%; background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 100%); color: #fff; border: none; padding: 0.6rem; font-size: 0.8rem; font-weight: 700; border-radius: 4px; cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(37,99,235,0.2); }
        .payment-card .btn-manage:hover { box-shadow: 0 4px 16px rgba(37,99,235,0.3); transform: translateY(-1px); }

        .payment-card .pending-actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
        .pending-actions .btn-confirm-sm, .pending-actions .btn-reject-sm { flex: 1; padding: 0.45rem; font-size: 0.75rem; font-weight: 700; border: none; border-radius: 3px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 0.3rem; text-transform: uppercase; letter-spacing: 0.03em; }
        .btn-confirm-sm { background: rgba(34,197,94,0.12); color: #16a34a; border: 1px solid rgba(34,197,94,0.2) !important; }
        .btn-confirm-sm:hover { background: #22c55e; color: #fff; }
        .btn-reject-sm { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.18) !important; }
        .btn-reject-sm:hover { background: #ef4444; color: #fff; }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 4rem 2rem; background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; }
        .empty-state i { font-size: 3rem; color: var(--text-secondary); opacity: 0.3; margin-bottom: 0.75rem; display: block; }
        .empty-state h4 { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; }
        .empty-state p { color: var(--text-secondary); margin: 0; }

        /* ===== MODAL OVERLAY & CONTAINER ===== */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; z-index: 1050; opacity: 0; transition: opacity 0.32s ease; backdrop-filter: blur(2px); }
        .modal-overlay.show { display: flex; opacity: 1; align-items: center; justify-content: center; }
        .modal-container { background: var(--card-bg); border-radius: 6px; box-shadow: 0 20px 60px rgba(0,0,0,0.18); max-width: 820px; width: 92%; max-height: 92vh; overflow-y: auto; transform: scale(0.94) translateY(14px); opacity: 0; transition: opacity 0.34s cubic-bezier(0.16,1,0.3,1), transform 0.34s cubic-bezier(0.16,1,0.3,1); border: 1px solid rgba(37,99,235,0.12); }
        .modal-large { max-width: 900px; width: 96%; }
        .modal-overlay.show .modal-container { opacity: 1; transform: scale(1) translateY(0); }
        /* --- Smooth close keyframes --- */
        @keyframes modal-overlay-out   { from { opacity: 1; } to { opacity: 0; } }
        @keyframes modal-container-out { from { opacity: 1; transform: scale(1) translateY(0); } to { opacity: 0; transform: scale(0.93) translateY(10px); } }
        .modal-overlay.is-closing                     { animation: modal-overlay-out 0.28s ease forwards; pointer-events: none; }
        .modal-overlay.is-closing .modal-container    { animation: modal-container-out 0.2s cubic-bezier(0.55,0,0.85,0.35) forwards; }
        .modal-container::-webkit-scrollbar { width: 5px; }
        .modal-container::-webkit-scrollbar-track { background: #f1f1f1; }
        .modal-container::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.4); border-radius: 4px; }

        /* ===== Unified Payment Modals (pm-) ===== */
        /* Container */
        .pm-container { max-width: 560px; display: flex !important; flex-direction: column; overflow: hidden !important; max-height: 92vh; border-radius: 8px !important; }
        .pm-container.pm-wide { max-width: 860px; }

        /* Header — dark gradient, matches property.php filter sidebar */
        .pm-header { display: flex; align-items: center; gap: .9rem; padding: 1.35rem 1.5rem; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); position: relative; flex-shrink: 0; overflow: hidden; }
        .pm-header::before { content: ''; position: absolute; top: 0; left: -100%; width: 200%; height: 100%; background: linear-gradient(90deg, transparent, rgba(212,175,55,.06), transparent); animation: pm-sweep 3.5s ease-in-out infinite; pointer-events: none; }
        @keyframes pm-sweep { 0% { left: -100%; } 100% { left: 100%; } }
        .pm-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; }
        .pm-header.pm-blue::after  { background: linear-gradient(90deg, var(--gold), var(--blue)); }
        .pm-header.pm-green::after { background: linear-gradient(90deg, #22c55e, #16a34a); }
        .pm-header.pm-red::after   { background: linear-gradient(90deg, #f87171, #dc2626); }

        /* Header icon — frosted glass on dark */
        .pm-header-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; background: rgba(255,255,255,.09); border: 1px solid rgba(255,255,255,.14); }
        .pm-header.pm-blue  .pm-header-icon i { color: var(--gold); }
        .pm-header.pm-green .pm-header-icon i { color: #4ade80; }
        .pm-header.pm-red   .pm-header-icon i { color: #f87171; }

        /* Header text */
        .pm-header-text { flex: 1; min-width: 0; }
        .pm-header-title { font-size: 1.02rem; font-weight: 800; color: #fff; margin: 0; letter-spacing: -.01em; }
        .pm-header-sub { font-size: .77rem; color: rgba(255,255,255,.5); margin: 0; }

        /* Badge + close */
        .pm-header-right { display: flex; align-items: center; gap: .6rem; flex-shrink: 0; }
        .pm-pay-badge { font-size: .63rem; font-weight: 700; background: rgba(212,175,55,.2); color: var(--gold); border: 1px solid rgba(212,175,55,.38); padding: .25rem .7rem; border-radius: 2px; letter-spacing: .8px; text-transform: uppercase; }
        .pm-close { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.16); color: rgba(255,255,255,.8); width: 32px; height: 32px; border-radius: 4px; font-size: .85rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .15s; }
        .pm-close:hover { background: rgba(239,68,68,.35); border-color: rgba(239,68,68,.55); color: #fff; }

        /* Body */
        .pm-body { padding: 1.5rem; background: #f8fafc; overflow-y: auto; flex: 1; min-height: 0; }
        .pm-body::-webkit-scrollbar { width: 5px; }
        .pm-body::-webkit-scrollbar-thumb { background: rgba(212,175,55,.3); border-radius: 4px; }

        /* Footer */
        .pm-footer { padding: 1rem 1.5rem; background: #fff; border-top: 1px solid rgba(37,99,235,.08); display: flex; gap: .6rem; justify-content: flex-end; flex-shrink: 0; }

        /* Buttons */
        .pm-btn { padding: .56rem 1.3rem; font-size: .84rem; font-weight: 600; border: none; border-radius: 4px; cursor: pointer; transition: all .18s; display: inline-flex; align-items: center; gap: .4rem; }
        .pm-btn-cancel  { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0 !important; }
        .pm-btn-cancel:hover  { border-color: rgba(37,99,235,.3) !important; background: rgba(37,99,235,.03); color: var(--blue); }
        .pm-btn-confirm { background: linear-gradient(135deg, #16a34a, #22c55e); color: #fff; box-shadow: 0 2px 8px rgba(34,197,94,.2); }
        .pm-btn-confirm:hover { filter: brightness(1.06); box-shadow: 0 4px 16px rgba(34,197,94,.3); }
        .pm-btn-reject  { background: linear-gradient(135deg, #dc2626, #ef4444); color: #fff; box-shadow: 0 2px 8px rgba(239,68,68,.2); }
        .pm-btn-reject:hover  { filter: brightness(1.06); box-shadow: 0 4px 16px rgba(239,68,68,.3); }

        /* Alert banners */
        .pm-alert { border-radius: 6px; padding: .8rem 1rem; font-size: .82rem; display: flex; align-items: flex-start; gap: .55rem; margin-bottom: 1rem; }
        .pm-alert.info { background: rgba(34,197,94,.06); border: 1px solid rgba(34,197,94,.15); color: #065f46; }
        .pm-alert.info i { color: #16a34a; margin-top: .1rem; flex-shrink: 0; }
        .pm-alert.warn  { background: rgba(245,158,11,.06); border: 1px solid rgba(245,158,11,.15); color: #92400e; }
        .pm-alert.warn i { color: #d97706; margin-top: .1rem; flex-shrink: 0; }

        /* Confirm modal internals */
        .pm-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: 1rem; }
        .pm-info-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: .7rem .9rem; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .pm-info-label { font-size: .63rem; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-secondary); margin-bottom: .2rem; }
        .pm-info-value { font-size: .95rem; font-weight: 700; color: var(--text-primary); }
        .pm-comm-preview { background: linear-gradient(135deg, rgba(34,197,94,.04), rgba(22,163,74,.07)); border: 1px solid rgba(34,197,94,.2); border-radius: 6px; padding: .9rem 1rem; display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .pm-comm-label { font-size: .63rem; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #16a34a; display: flex; align-items: center; gap: .35rem; margin-bottom: .25rem; }
        .pm-comm-val { font-size: 1.3rem; font-weight: 900; color: #16a34a; }
        .pm-field { margin-bottom: 1rem; }
        .pm-field:last-child { margin-bottom: 0; }
        .pm-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-secondary); margin-bottom: .45rem; display: flex; align-items: center; gap: .4rem; }
        .pm-label i { color: var(--gold-dark); font-size: .8rem; }
        .pm-input { width: 100%; padding: .6rem .85rem; border: 1px solid #e2e8f0; border-radius: 4px; font-size: .88rem; font-weight: 500; color: var(--text-primary); background: #fff; transition: all .2s; }
        .pm-input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.08); outline: none; }
        .pm-input.reject-focus:focus { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.08); }
        .pm-textarea { min-height: 85px; resize: vertical; }
        .pm-divider { height: 1px; background: linear-gradient(90deg, transparent, #e2e8f0, transparent); margin: .6rem 0 1rem; }
        .pm-error { color: #dc2626; font-size: .78rem; font-weight: 600; margin-top: .4rem; display: flex; align-items: center; gap: .3rem; }

        /* ===== Payment Detail Doc Content (pd-) ===== */
        .pd-section { margin-bottom: 1.5rem; }
        .pd-section:last-child { margin-bottom: 0; }
        .pd-section-title { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); display: flex; align-items: center; gap: .45rem; padding-bottom: .6rem; border-bottom: 1px solid #e2e8f0; margin-bottom: 1rem; }
        .pd-section-title i { color: var(--gold-dark); font-size: .9rem; }
        .pd-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
        .pd-info-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: .7rem .9rem; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
        .pd-info-item.pd-full { grid-column: 1 / -1; }
        .pd-info-label { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--text-secondary); margin-bottom: .2rem; }
        .pd-info-value { font-size: .9rem; font-weight: 600; color: var(--text-primary); line-height: 1.45; }
        .pd-status-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .22rem .72rem; border-radius: 14px; font-size: .72rem; font-weight: 700; }
        .pd-status-pending   { background: rgba(245,158,11,.1);  color: #d97706; border: 1px solid rgba(245,158,11,.2); }
        .pd-status-confirmed { background: rgba(34,197,94,.1);   color: #16a34a; border: 1px solid rgba(34,197,94,.2); }
        .pd-status-rejected  { background: rgba(239,68,68,.1);   color: #dc2626; border: 1px solid rgba(239,68,68,.2); }
        .pd-docs-list { display: flex; flex-direction: column; gap: .6rem; }
        .pd-doc-item { display: flex; align-items: center; gap: .9rem; padding: .85rem 1rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; text-decoration: none; color: var(--text-primary); transition: all .2s; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
        .pd-doc-item:hover { border-color: rgba(37,99,235,.35); background: rgba(37,99,235,.02); transform: translateX(3px); box-shadow: 0 3px 12px rgba(37,99,235,.07); }
        .pd-doc-icon { width: 42px; height: 42px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .pd-doc-icon.pdf { background: rgba(239,68,68,.08);  color: #dc2626; border: 1px solid rgba(239,68,68,.15); }
        .pd-doc-icon.img { background: rgba(34,197,94,.08);  color: #16a34a; border: 1px solid rgba(34,197,94,.15); }
        .pd-doc-icon.doc { background: rgba(37,99,235,.08);  color: var(--blue); border: 1px solid rgba(37,99,235,.15); }
        .pd-doc-icon.gen { background: rgba(212,175,55,.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,.15); }
        .pd-doc-info { flex: 1; min-width: 0; }
        .pd-doc-name { font-size: .88rem; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pd-doc-date { font-size: .73rem; color: var(--text-secondary); margin-top: .1rem; display: flex; align-items: center; gap: .3rem; }
        .pd-doc-dl { margin-left: auto; color: var(--blue); font-size: 1.1rem; flex-shrink: 0; opacity: .45; transition: opacity .2s; }
        .pd-doc-item:hover .pd-doc-dl { opacity: 1; }
        .pd-empty { text-align: center; padding: 2.5rem 1rem; color: var(--text-secondary); }
        .pd-empty i { font-size: 2.5rem; display: block; margin-bottom: .75rem; opacity: .2; }
        .pd-empty p { font-size: .88rem; margin: 0; }
        .pd-loading { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 3.5rem 1rem; gap: .75rem; }
        .pd-loading-ring { width: 40px; height: 40px; border-radius: 50%; border: 3px solid rgba(212,175,55,.15); border-top-color: var(--gold-dark); animation: pc-spin 1s linear infinite; }
        .pd-loading p { font-size: .82rem; color: var(--text-secondary); margin: 0; }

        /* ===== TOAST NOTIFICATIONS ===== */
        #toastContainer { position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.6rem; pointer-events: none; }
        .app-toast { display: flex; align-items: flex-start; gap: 0.85rem; background: #ffffff; border-radius: 12px; padding: 0.9rem 1.1rem; min-width: 300px; max-width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,0.16), 0 0 0 1px rgba(0,0,0,0.06); pointer-events: all; position: relative; overflow: hidden; animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards; }
        @keyframes toast-in { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }
        .app-toast::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast.toast-warning::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }
        .toast-warning .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .app-toast-body { flex: 1; min-width: 0; }
        .app-toast-title { font-size: 0.82rem; font-weight: 700; color: #111827; margin-bottom: 0.2rem; }
        .app-toast-msg { font-size: 0.78rem; color: #6b7280; line-height: 1.4; word-break: break-word; }
        .app-toast-close { background: none; border: none; cursor: pointer; color: #9ca3af; font-size: 0.8rem; padding: 0; line-height: 1; flex-shrink: 0; transition: color .2s; }
        .app-toast-close:hover { color: #374151; }
        .app-toast-progress { position: absolute; bottom: 0; left: 0; height: 2px; border-radius: 0 0 0 12px; }
        .toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        .toast-warning .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* ===== PROCESSING OVERLAY ===== */
        .processing-overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(15,23,42,0.45); backdrop-filter: blur(6px); z-index: 2000; }
        .processing-overlay.show { display: flex; }
        .processing-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 0; width: 380px; text-align: center; box-shadow: 0 8px 32px rgba(37,99,235,0.08), 0 24px 64px rgba(0,0,0,0.15); position: relative; overflow: hidden; animation: pc-pop .3s cubic-bezier(.34,1.56,.64,1) forwards; }
        @keyframes pc-pop { from { opacity:0; transform: scale(.92) translateY(14px); } to { opacity:1; transform: scale(1) translateY(0); } }
        .processing-card::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .processing-card::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(212,175,55,0.04), transparent); animation: pc-sweep 2.2s ease-in-out infinite; pointer-events: none; z-index: 0; }
        @keyframes pc-sweep { 0% { left: -100%; } 100% { left: 100%; } }
        .pc-header { position: relative; z-index: 1; padding: 2rem 2rem 0; }
        .pc-ring-wrap { position: relative; width: 72px; height: 72px; margin: 0 auto 1.25rem; }
        .pc-ring { position: absolute; inset: 0; border-radius: 50%; border: 2px solid transparent; border-top-color: var(--gold); border-right-color: rgba(212,175,55,0.2); animation: pc-spin 1s linear infinite; }
        .pc-ring-inner { position: absolute; inset: 9px; border-radius: 50%; border: 2px solid transparent; border-bottom-color: var(--blue); border-left-color: rgba(37,99,235,0.15); animation: pc-spin-rev .75s linear infinite; }
        @keyframes pc-spin { to { transform: rotate(360deg); } }
        @keyframes pc-spin-rev { to { transform: rotate(-360deg); } }
        .pc-icon-center { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; color: var(--gold-dark); }
        .pc-title { font-size: 1.05rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.2rem; }
        .pc-subtitle { font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 0; }
        .pc-steps-wrap { position: relative; z-index: 1; padding: 1.25rem 1.75rem 1.75rem; }
        .pc-steps { display: flex; flex-direction: column; gap: 0.35rem; text-align: left; }
        .pc-step { display: flex; align-items: center; gap: 0.65rem; font-size: 0.78rem; font-weight: 500; color: #9ca3af; padding: 0.5rem 0.7rem; border-radius: 4px; border: 1px solid transparent; transition: all .3s ease; }
        .pc-step.active { color: var(--text-primary); background: rgba(212,175,55,0.06); border-color: rgba(212,175,55,0.15); }
        .pc-step.done { color: #16a34a; background: rgba(22,163,74,0.04); border-color: rgba(22,163,74,0.1); }
        .pc-step-dot { width: 24px; height: 24px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; flex-shrink: 0; background: #f3f4f6; color: #9ca3af; border: 1px solid #e2e8f0; transition: all .3s; }
        .pc-step.active .pc-step-dot { background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.15)); color: var(--gold-dark); border-color: rgba(212,175,55,0.3); animation: pc-pulse .8s ease-in-out infinite alternate; }
        @keyframes pc-pulse { from { box-shadow: 0 0 0 0 rgba(212,175,55,0.25); } to { box-shadow: 0 0 0 4px rgba(212,175,55,0); } }
        .pc-step.done .pc-step-dot { background: rgba(22,163,74,0.1); color: #16a34a; border-color: rgba(22,163,74,0.25); }
        .pc-progress { height: 3px; background: #f1f5f9; position: relative; overflow: hidden; }
        .pc-progress-bar { position: absolute; top: 0; left: 0; height: 100%; width: 0%; background: linear-gradient(90deg, var(--gold), var(--blue)); border-radius: 0 2px 2px 0; transition: width 0.5s ease; }

        /* ===== SKELETON SCREEN ===== */
        @keyframes sk-shimmer { 0% { background-position: -1600px 0; } 100% { background-position: 1600px 0; } }
        .sk-shimmer { background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%); background-size: 1600px 100%; animation: sk-shimmer 1.6s ease-in-out infinite; border-radius: 4px; }
        #page-content { display: none; }
        .sk-page-header { background: #fff; border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .sk-page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent); }
        .sk-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .sk-kpi-card { background: #fff; border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem; }
        .sk-kpi-icon { width: 48px; height: 48px; border-radius: 4px; flex-shrink: 0; }
        .sk-action-bar { background: #fff; border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 0.85rem 1.25rem; margin-bottom: 1.25rem; display: flex; gap: 0.75rem; align-items: center; position: relative; overflow: hidden; }
        .sk-action-bar::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent); }
        .sk-tabs { background: #fff; border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; margin-bottom: 1.5rem; padding: 0 1rem; display: flex; gap: 0.75rem; align-items: center; height: 54px; position: relative; overflow: hidden; }
        .sk-tabs::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent); }
        .sk-content-wrap { background: #fff; border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; }
        .sk-payments-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; padding: 1.5rem; }
        .sk-payment-card { background: #fff; border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; }
        .sk-card-img { height: 180px; width: 100%; }
        .sk-card-body { padding: 1rem 1.25rem; }
        .sk-card-footer { padding: 0 1.25rem 1.25rem; }
        .sk-line { display: block; border-radius: 4px; }

        @media (max-width: 1200px) { .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); } .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .page-header h1 { font-size: 1.3rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .payment-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
            .payment-tabs .nav-link { white-space: nowrap; padding: 0.75rem 0.85rem; font-size: 0.8rem; }
            .payments-grid { grid-template-columns: 1fr; }
            .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .sk-payments-grid { grid-template-columns: 1fr; }
            .modal-container { width: 98%; }
            .pm-info-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .payment-tabs .nav-link { padding: 0.6rem 0.7rem; font-size: 0.75rem; }
            .sk-kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        }
    </style>
</head>
<body>
    <?php
    $active_page = 'admin_rental_payments.php';
    include 'admin_sidebar.php';
    include 'admin_navbar.php';
    ?>

    <div class="admin-content">

        <noscript><style>
            #sk-screen   { display: none !important; }
            #page-content { display: block !important; opacity: 1 !important; }
        </style></noscript>

        <!-- ===== SKELETON SCREEN ===== -->
        <div id="sk-screen" role="presentation" aria-hidden="true">
            <div class="sk-page-header">
                <div class="sk-line sk-shimmer" style="width:220px;height:22px;margin-bottom:10px;"></div>
                <div class="sk-line sk-shimmer" style="width:360px;height:13px;"></div>
            </div>
            <div class="sk-kpi-grid">
                <div class="sk-kpi-card"><div class="sk-kpi-icon sk-shimmer"></div><div style="flex:1;"><div class="sk-line sk-shimmer" style="width:65%;height:10px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:40%;height:20px;"></div></div></div>
                <div class="sk-kpi-card"><div class="sk-kpi-icon sk-shimmer"></div><div style="flex:1;"><div class="sk-line sk-shimmer" style="width:70%;height:10px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:35%;height:20px;"></div></div></div>
                <div class="sk-kpi-card"><div class="sk-kpi-icon sk-shimmer"></div><div style="flex:1;"><div class="sk-line sk-shimmer" style="width:60%;height:10px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:30%;height:20px;"></div></div></div>
                <div class="sk-kpi-card"><div class="sk-kpi-icon sk-shimmer"></div><div style="flex:1;"><div class="sk-line sk-shimmer" style="width:55%;height:10px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:35%;height:20px;"></div></div></div>
            </div>
            <div class="sk-action-bar">
                <div class="sk-shimmer" style="flex:1;height:36px;border-radius:4px;"></div>
                <div class="sk-shimmer" style="width:90px;height:36px;border-radius:4px;flex-shrink:0;"></div>
            </div>
            <div class="sk-tabs">
                <div class="sk-shimmer" style="width:75px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:85px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:80px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:80px;height:20px;border-radius:3px;"></div>
            </div>
            <div class="sk-content-wrap">
                <div class="sk-payments-grid">
                    <div class="sk-payment-card"><div class="sk-card-img sk-shimmer"></div><div class="sk-card-body"><div class="sk-line sk-shimmer" style="width:82%;height:16px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:52%;height:12px;margin-bottom:12px;"></div><div style="display:flex;gap:6px;margin-bottom:12px;"><div class="sk-shimmer" style="width:82px;height:10px;border-radius:3px;"></div><div class="sk-shimmer" style="width:95px;height:10px;border-radius:3px;"></div></div></div><div class="sk-card-footer"><div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div></div></div>
                    <div class="sk-payment-card"><div class="sk-card-img sk-shimmer"></div><div class="sk-card-body"><div class="sk-line sk-shimmer" style="width:75%;height:16px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:45%;height:12px;margin-bottom:12px;"></div><div style="display:flex;gap:6px;margin-bottom:12px;"><div class="sk-shimmer" style="width:78px;height:10px;border-radius:3px;"></div><div class="sk-shimmer" style="width:88px;height:10px;border-radius:3px;"></div></div></div><div class="sk-card-footer"><div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div></div></div>
                    <div class="sk-payment-card"><div class="sk-card-img sk-shimmer"></div><div class="sk-card-body"><div class="sk-line sk-shimmer" style="width:88%;height:16px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:58%;height:12px;margin-bottom:12px;"></div><div style="display:flex;gap:6px;margin-bottom:12px;"><div class="sk-shimmer" style="width:85px;height:10px;border-radius:3px;"></div><div class="sk-shimmer" style="width:92px;height:10px;border-radius:3px;"></div></div></div><div class="sk-card-footer"><div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div></div></div>
                </div>
            </div>
        </div>

        <!-- ===== REAL PAGE CONTENT ===== -->
        <div id="page-content">

        <script>
        document.addEventListener('skeleton:hydrated', function() {
            <?php if ($success_message): ?>
                showToast('success', 'Success', '<?= addslashes(htmlspecialchars($success_message)) ?>', 5000);
            <?php endif; ?>
            <?php if ($error_message): ?>
                showToast('error', 'Error', '<?= addslashes(htmlspecialchars($error_message)) ?>', 6000);
            <?php endif; ?>
            <?php if ($status_counts['Pending'] > 0): ?>
                setTimeout(function() {
                    showToast(
                        'warning',
                        '<?= $status_counts['Pending'] === 1 ? "1 Pending Payment" : $status_counts['Pending'] . " Pending Payments" ?>',
                        '<?= $status_counts['Pending'] === 1
                            ? "1 rent payment is awaiting your review."
                            : $status_counts['Pending'] . " rent payments are awaiting your review." ?>',
                        6000
                    );
                }, <?= ($success_message || $error_message) ? 700 : 400 ?>);
            <?php endif; ?>
        });
        </script>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1>Rental Payments</h1>
                    <p class="subtitle">Review and confirm rent payment records submitted by agents</p>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fas fa-clock"></i></div>
                <div><div class="kpi-label">Pending Review</div><div class="kpi-value"><?= $status_counts['Pending'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fas fa-check-circle"></i></div>
                <div><div class="kpi-label">Confirmed</div><div class="kpi-value"><?= $status_counts['Confirmed'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="fas fa-times-circle"></i></div>
                <div><div class="kpi-label">Rejected</div><div class="kpi-value"><?= $status_counts['Rejected'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fas fa-peso-sign"></i></div>
                <div><div class="kpi-label">Confirmed Revenue</div><div class="kpi-value">&#8369;<?= number_format($total_confirmed_revenue, 0) ?></div></div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div class="action-bar-left">
                <div class="action-search-wrap">
                    <i class="bi bi-search ab-search-icon"></i>
                    <input type="text" id="quickSearchInput" placeholder="Search property, agent, tenant or period..." autocomplete="off">
                </div>
            </div>
            <div class="action-bar-right">
                <button class="btn-outline-admin" id="openFilterBtn">
                    <i class="bi bi-funnel"></i>
                    Filters
                    <span class="filter-count-badge" id="filterCountBadge" style="display:none;">0</span>
                </button>
            </div>
        </div>

        <!-- Status Tabs -->
        <div class="payment-tabs">
            <ul class="nav nav-tabs">
                <?php foreach ($status_tabs as $tabKey => $tabInfo): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $active_status === $tabKey ? 'active' : '' ?>"
                           href="?status=<?= $tabKey ?>"
                           data-tab="<?= $tabKey ?>">
                            <i class="bi <?= $tabInfo['icon'] ?>"></i>
                            <?= $tabKey ?>
                            <span class="tab-badge badge-<?= strtolower($tabKey) ?>"><?= $tabInfo['count'] ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="tab-content">
                <?php
                    $display = $active_status === 'All'
                        ? $payments
                        : array_filter($payments, fn($p) => $p['status'] === $active_status);
                ?>
                <?php if (empty($display)): ?>
                    <div class="empty-state">
                        <i class="bi bi-cash-stack"></i>
                        <h4>No <?= $active_status === 'All' ? '' : $active_status ?> Payments</h4>
                        <p>There are no <?= strtolower($active_status) ?> rental payments to display.</p>
                    </div>
                <?php else: ?>
                    <div class="payments-grid">
                        <?php foreach ($display as $p): ?>
                            <div class="payment-card" data-payment='<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>'>
                                <div class="card-img-wrap">
                                    <?php if (!empty($p['property_image'])): ?>
                                        <img src="<?= htmlspecialchars($p['property_image']) ?>" alt="Property" onerror="this.src='uploads/default-property.jpg'">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#adb5bd;"><i class="bi bi-image" style="font-size:2.5rem;"></i></div>
                                    <?php endif; ?>
                                    <div class="img-overlay"></div>
                                    <div class="type-badge"><i class="bi bi-cash-coin"></i> Rent Payment</div>
                                    <?php $badgeClass = strtolower($p['status']); ?>
                                    <div class="status-badge <?= $badgeClass ?>">
                                        <i class="bi bi-circle-fill" style="font-size:0.35rem;"></i>
                                        <?= $p['status'] ?>
                                    </div>
                                    <div class="price-overlay">
                                        <div class="price">&#8369;<?= number_format($p['payment_amount'], 0) ?></div>
                                    </div>
                                </div>

                                <div class="card-body-content">
                                    <h3 class="prop-address" title="<?= htmlspecialchars($p['StreetAddress']) ?>"><?= htmlspecialchars($p['StreetAddress']) ?></h3>
                                    <div class="prop-location"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($p['City'] . ', ' . ($p['Province'] ?? '')) ?></div>

                                    <div class="payment-meta-row">
                                        <span class="payment-meta-item agent-meta"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($p['agent_first'] . ' ' . $p['agent_last']) ?></span>
                                        <span class="payment-meta-item tenant-meta"><i class="bi bi-person"></i> <?= htmlspecialchars($p['tenant_name']) ?></span>
                                        <span class="payment-meta-item period-meta"><i class="bi bi-calendar-range"></i> <?= date('M d', strtotime($p['period_start'])) ?> &ndash; <?= date('M d', strtotime($p['period_end'])) ?></span>
                                        <span class="payment-meta-item date-meta"><i class="bi bi-calendar3"></i> <?= date('M d, Y', strtotime($p['payment_date'])) ?></span>
                                        <?php if ($p['commission_amount']): ?>
                                            <span class="payment-meta-item comm-meta"><i class="bi bi-coin"></i> &#8369;<?= number_format($p['commission_amount'], 2) ?> comm.</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="card-footer-section">
                                        <button class="btn-manage" onclick="viewPaymentDocs(<?= $p['payment_id'] ?>)">
                                            <i class="bi bi-file-earmark-text"></i> View Documents
                                        </button>
                                        <?php if ($p['status'] === 'Pending'): ?>
                                            <div class="pending-actions">
                                                <button class="btn-confirm-sm" onclick="openConfirmModal(<?= $p['payment_id'] ?>, <?= $p['payment_amount'] ?>, <?= $p['commission_rate'] ?>)">
                                                    <i class="bi bi-check-lg"></i> Confirm
                                                </button>
                                                <button class="btn-reject-sm" onclick="openRejectModal(<?= $p['payment_id'] ?>)">
                                                    <i class="bi bi-x-lg"></i> Reject
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        </div><!-- /#page-content -->
    </div><!-- /.admin-content -->

    <!-- ===== View Documents Modal ===== -->
    <div class="modal-overlay" id="docsModal">
        <div class="modal-container pm-container pm-wide">
            <div class="pm-header pm-blue">
                <div class="pm-header-icon blue"><i class="bi bi-file-earmark-text"></i></div>
                <div class="pm-header-text">
                    <div class="pm-header-title">Payment Documents</div>
                    <div class="pm-header-sub">Payment details and submitted proof documents</div>
                </div>
                <div class="pm-header-right">
                    <span class="pm-pay-badge" id="modalPayIdBadge"></span>
                    <button class="pm-close" onclick="closeModal('docsModal')"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
            <div class="pm-body" id="docsContent">
                <div style="text-align:center;padding:3rem;"><div class="spinner-border text-secondary"></div></div>
            </div>
        </div>
    </div>

    <!-- ===== Confirm Payment Modal ===== -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal-container pm-container">
            <div class="pm-header pm-green">
                <div class="pm-header-icon green"><i class="bi bi-check-circle"></i></div>
                <div class="pm-header-text">
                    <div class="pm-header-title">Confirm Payment</div>
                    <div class="pm-header-sub">Confirming will record the agent&rsquo;s commission</div>
                </div>
                <div class="pm-header-right">
                    <button class="pm-close" onclick="closeModal('confirmModal')"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
            <form id="confirmForm" style="display:flex;flex-direction:column;flex:1;min-height:0;">
                <div class="pm-body">
                    <input type="hidden" name="payment_id" id="confirm_payment_id">
                    <div class="pm-alert info">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>Confirming this payment will automatically calculate and create a commission record for the assigned agent based on the pre-set commission rate.</span>
                    </div>
                    <div class="pm-info-grid">
                        <div class="pm-info-item">
                            <div class="pm-info-label">Payment Amount</div>
                            <div class="pm-info-value" id="fsm_amount_display">&mdash;</div>
                        </div>
                        <div class="pm-info-item">
                            <div class="pm-info-label">Commission Rate</div>
                            <div class="pm-info-value" id="fsm_rate_display">&mdash;</div>
                        </div>
                    </div>
                    <div class="pm-comm-preview">
                        <div>
                            <div class="pm-comm-label"><i class="bi bi-coin"></i> Agent Commission</div>
                            <div class="pm-comm-val" id="commPreviewVal">&mdash;</div>
                        </div>
                        <i class="bi bi-calculator" style="font-size:1.5rem;color:rgba(212,175,55,0.3);"></i>
                    </div>
                    <div class="pm-divider"></div>
                    <div class="pm-field">
                        <label class="pm-label" for="confirm_notes"><i class="bi bi-chat-left-text"></i> Admin Notes <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                        <textarea class="pm-input pm-textarea" id="confirm_notes" name="admin_notes" placeholder="Any additional notes about this payment confirmation&hellip;" maxlength="2000"></textarea>
                    </div>
                </div>
                <div class="pm-footer">
                    <button type="button" class="pm-btn pm-btn-cancel" onclick="closeModal('confirmModal')"><i class="bi bi-x-lg"></i> Cancel</button>
                    <button type="submit" class="pm-btn pm-btn-confirm" id="confirmBtn"><i class="bi bi-check2-circle"></i> Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Reject Payment Modal ===== -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal-container pm-container">
            <div class="pm-header pm-red">
                <div class="pm-header-icon red"><i class="bi bi-x-octagon"></i></div>
                <div class="pm-header-text">
                    <div class="pm-header-title">Reject Payment</div>
                    <div class="pm-header-sub">Provide a clear reason so the agent can resubmit</div>
                </div>
                <div class="pm-header-right">
                    <button class="pm-close" onclick="closeModal('rejectModal')"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
            <div class="pm-body">
                <div class="pm-alert warn">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>This action will mark the payment as <strong>Rejected</strong>. The agent will be notified and may resubmit with corrected information.</span>
                </div>
                <div class="pm-field">
                    <label class="pm-label" for="reasonInput"><i class="bi bi-chat-left-text"></i> Rejection Reason <span style="color:#dc2626;">*</span></label>
                    <textarea class="pm-input pm-textarea reject-focus" id="reasonInput" placeholder="Explain why this payment is being rejected&hellip;"></textarea>
                    <div id="reasonError" class="pm-error" style="display:none;"><i class="bi bi-exclamation-circle"></i> A reason is required.</div>
                </div>
            </div>
            <div class="pm-footer">
                <button type="button" class="pm-btn pm-btn-cancel" onclick="closeModal('rejectModal')"><i class="bi bi-x-lg"></i> Cancel</button>
                <button type="button" class="pm-btn pm-btn-reject" id="submitRejectBtn"><i class="bi bi-x-octagon"></i> Reject Payment</button>
            </div>
        </div>
    </div>

    <!-- ===== Processing Overlay ===== -->
    <div id="processingOverlay" class="processing-overlay">
        <div class="processing-card">
            <div class="pc-header">
                <div class="pc-ring-wrap">
                    <div class="pc-ring"></div>
                    <div class="pc-ring-inner"></div>
                    <div class="pc-icon-center"><i class="bi bi-cash-coin" id="pcIcon"></i></div>
                </div>
                <div class="pc-title" id="pcTitle">Processing Payment</div>
                <div class="pc-subtitle" id="pcSubtitle">Please wait&hellip;</div>
            </div>
            <div class="pc-steps-wrap">
                <div class="pc-steps">
                    <div class="pc-step" id="pcStep1"><div class="pc-step-dot"><i class="bi bi-check-lg"></i></div><span>Validating payment data</span></div>
                    <div class="pc-step" id="pcStep2"><div class="pc-step-dot"><i class="bi bi-check-lg"></i></div><span>Confirming payment record</span></div>
                    <div class="pc-step" id="pcStep3"><div class="pc-step-dot"><i class="bi bi-check-lg"></i></div><span>Recording commission</span></div>
                    <div class="pc-step" id="pcStep4"><div class="pc-step-dot"><i class="bi bi-envelope-paper"></i></div><span>Sending notification</span></div>
                </div>
            </div>
            <div class="pc-progress"><div class="pc-progress-bar" id="pcProgressBar"></div></div>
        </div>
    </div>

    <!-- ===== Filter Sidebar ===== -->
    <div class="sf-sidebar" id="sfSidebar">
        <div class="sf-overlay" id="sfOverlay"></div>
        <div class="sf-content">
            <div class="sf-header">
                <h4><i class="bi bi-funnel-fill"></i> Advanced Filters</h4>
                <div class="sf-header-right">
                    <span class="sf-active-pill" id="sfActivePill"><i class="bi bi-check2"></i> <span id="sfActivePillText">0 active</span></span>
                    <button class="btn-close-sf" id="sfCloseBtn"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>

            <div class="sf-results-bar">
                <i class="bi bi-list-check"></i>
                <span class="sf-results-num" id="sfResultsNum">&mdash;</span>
                <span class="sf-results-label">payments match your filters</span>
            </div>

            <div class="sf-body">
                <!-- Search -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-search"></i> Search</div>
                    <div class="sf-search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" id="sfSearchInput" placeholder="Address, city, tenant, agent, period…">
                    </div>
                </div>

                <!-- Payment Amount Range -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-cash-stack"></i> Payment Amount Range</div>
                    <div class="price-range-inputs">
                        <div class="price-input">
                            <span class="currency-sym">₱</span>
                            <input type="number" id="sfAmountMin" placeholder="Min" min="0" step="1000">
                        </div>
                        <span class="range-divider">—</span>
                        <div class="price-input">
                            <span class="currency-sym">₱</span>
                            <input type="number" id="sfAmountMax" placeholder="Max" min="0" step="1000">
                        </div>
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-amount-range="0-10000">Under 10K</button>
                        <button class="quick-filter-btn" data-amount-range="10000-30000">10K – 30K</button>
                        <button class="quick-filter-btn" data-amount-range="30000-60000">30K – 60K</button>
                        <button class="quick-filter-btn" data-amount-range="60000-999999999">60K+</button>
                    </div>
                </div>

                <!-- Submitted Date -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-calendar3"></i> Submitted Date</div>
                    <div class="date-range-inputs">
                        <input type="date" id="sfDateFrom" title="Date from">
                        <span class="range-divider">—</span>
                        <input type="date" id="sfDateTo" title="Date to">
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-date-range="this_month">This Month</button>
                        <button class="quick-filter-btn" data-date-range="last_30">Last 30 Days</button>
                        <button class="quick-filter-btn" data-date-range="this_year">This Year</button>
                    </div>
                </div>

                <!-- Agent -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-person-badge"></i> Agent</div>
                    <select id="sfAgentSelect" class="sf-select">
                        <option value="">All Agents</option>
                        <?php
                        $agentSet = [];
                        foreach ($payments as $p) {
                            $aKey = $p['agent_id'];
                            if (!isset($agentSet[$aKey])) {
                                $agentSet[$aKey] = htmlspecialchars($p['agent_first'] . ' ' . $p['agent_last']);
                            }
                        }
                        foreach ($agentSet as $aid => $aname): ?>
                            <option value="<?= $aid ?>"><?= $aname ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- City -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-geo-alt"></i> City</div>
                    <select id="sfCitySelect" class="sf-select">
                        <option value="">All Cities</option>
                        <?php
                        $citySet = [];
                        foreach ($payments as $p) {
                            $c = trim($p['City'] ?? '');
                            if ($c && !in_array($c, $citySet)) $citySet[] = $c;
                        }
                        sort($citySet);
                        foreach ($citySet as $city): ?>
                            <option value="<?= htmlspecialchars($city) ?>"><?= htmlspecialchars($city) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Sort -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-sort-down"></i> Sort By</div>
                    <select id="sfSortSelect" class="sf-select">
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="amount_high">Amount: High → Low</option>
                        <option value="amount_low">Amount: Low → High</option>
                        <option value="agent_az">Agent A → Z</option>
                    </select>
                </div>
            </div>

            <div class="sf-footer">
                <button class="btn btn-reset" id="sfResetBtn"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset All</button>
                <button class="btn btn-apply" id="sfApplyBtn"><i class="bi bi-check2 me-1"></i>Apply Filters</button>
            </div>
        </div>
    </div>

    <?php include 'logout_modal.php'; ?>
    <div id="toastContainer"></div>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
    /* ===== TOAST NOTIFICATION SYSTEM ===== */
    function showToast(type, title, message, duration) {
        duration = duration || 4500;
        var icons = { success:'bi-check-circle-fill', error:'bi-x-octagon-fill', info:'bi-info-circle-fill', warning:'bi-exclamation-triangle-fill', money:'bi-cash-coin' };
        var container = document.getElementById('toastContainer');
        var toast = document.createElement('div');
        toast.className = 'app-toast toast-' + type;
        toast.innerHTML =
            '<div class="app-toast-icon"><i class="bi ' + (icons[type]||icons.info) + '"></i></div>' +
            '<div class="app-toast-body"><div class="app-toast-title">' + title + '</div><div class="app-toast-msg">' + message + '</div></div>' +
            '<button class="app-toast-close" onclick="this.parentElement.classList.add(\'toast-out\');setTimeout(()=>this.parentElement.remove(),300)">&times;</button>' +
            '<div class="app-toast-progress" style="animation: toast-progress ' + duration + 'ms linear forwards;"></div>';
        container.appendChild(toast);
        setTimeout(function() { toast.classList.add('toast-out'); setTimeout(function() { toast.remove(); }, 300); }, duration);
    }

    /* ===== MODAL HELPERS ===== */
    var _modalTimers = {};
    function openModal(id) {
        var el = document.getElementById(id);
        clearTimeout(_modalTimers[id]);
        delete _modalTimers[id];
        el.classList.remove('is-closing');  // cancel any in-flight close animation
        el.style.display = 'flex';
        requestAnimationFrame(function() { el.classList.add('show'); });
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        var el = document.getElementById(id);
        if (!el || !el.classList.contains('show')) return;
        el.classList.remove('show');
        el.classList.add('is-closing');     // triggers CSS keyframe closing animation
        clearTimeout(_modalTimers[id]);
        _modalTimers[id] = setTimeout(function() {
            el.style.display = 'none';
            el.classList.remove('is-closing');
            if (!document.querySelector('.modal-overlay.show')) {
                document.body.style.overflow = '';
            }
        }, 300);
    }
    document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal(overlay.id);
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.show').forEach(function(m) { closeModal(m.id); });
        }
    });

    /* ===== VIEW DOCUMENTS ===== */
    function viewPaymentDocs(pid) {
        document.getElementById('modalPayIdBadge').textContent = 'PAY-' + pid;
        document.getElementById('docsContent').innerHTML = '<div class="pd-loading"><div class="pd-loading-ring"></div><p>Loading payment details&hellip;</p></div>';
        openModal('docsModal');
        fetch('admin_rental_payment_details.php?payment_id=' + pid)
            .then(function(r) { return r.text(); })
            .then(function(html) { document.getElementById('docsContent').innerHTML = html; })
            .catch(function() { document.getElementById('docsContent').innerHTML = '<div class="pd-empty"><i class="bi bi-exclamation-triangle"></i><p>Failed to load payment details.</p></div>'; });
    }

    /* ===== CONFIRM MODAL ===== */
    function openConfirmModal(pid, amount, rate) {
        document.getElementById('confirm_payment_id').value = pid;
        document.getElementById('fsm_amount_display').textContent = '\u20B1' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('fsm_rate_display').textContent = rate + '%';
        var comm = (parseFloat(amount) * parseFloat(rate) / 100);
        document.getElementById('commPreviewVal').textContent = '\u20B1' + comm.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('confirm_notes').value = '';
        openModal('confirmModal');
    }

    // Confirm form submit
    document.getElementById('confirmForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.getElementById('confirmBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:pc-spin 1s linear infinite;"></i> Processing...';

        showProcessingOverlay('Confirming Payment', 'Recording commission...');

        fetch('admin_confirm_rental_payment.php', { method: 'POST', body: new FormData(this) })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                hideProcessingOverlay();
                if (data.ok) {
                    closeModal('confirmModal');
                    showToast('success', 'Payment Confirmed', data.message, 5000);
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showToast('error', 'Error', data.message, 6000);
                }
            })
            .catch(function() {
                hideProcessingOverlay();
                showToast('error', 'Error', 'An error occurred. Please try again.', 6000);
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2-circle"></i> Confirm Payment';
            });
    });

    /* ===== REJECT MODAL ===== */
    var currentRejectPid = null;

    function openRejectModal(pid) {
        currentRejectPid = pid;
        document.getElementById('reasonInput').value = '';
        document.getElementById('reasonError').style.display = 'none';
        openModal('rejectModal');
    }

    document.getElementById('submitRejectBtn').addEventListener('click', function() {
        var reason = document.getElementById('reasonInput').value.trim();
        if (!reason) {
            document.getElementById('reasonError').style.display = 'flex';
            return;
        }
        document.getElementById('reasonError').style.display = 'none';

        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:pc-spin 1s linear infinite;"></i> Rejecting...';

        var formData = new FormData();
        formData.append('payment_id', currentRejectPid);
        formData.append('admin_notes', reason);

        fetch('admin_reject_rental_payment.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    closeModal('rejectModal');
                    showToast('success', 'Rejected', data.message, 5000);
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showToast('error', 'Error', data.message, 6000);
                }
            })
            .catch(function() {
                showToast('error', 'Error', 'An error occurred. Please try again.', 6000);
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-x-octagon"></i> Reject';
            });
    });

    /* ===== PROCESSING OVERLAY ===== */
    function showProcessingOverlay(title, subtitle) {
        document.getElementById('pcTitle').textContent = title || 'Processing';
        document.getElementById('pcSubtitle').textContent = subtitle || 'Please wait\u2026';
        document.getElementById('processingOverlay').classList.add('show');
        animateProcessingSteps();
    }
    function hideProcessingOverlay() {
        document.getElementById('processingOverlay').classList.remove('show');
    }
    function animateProcessingSteps() {
        var steps = ['pcStep1', 'pcStep2', 'pcStep3', 'pcStep4'];
        var bar = document.getElementById('pcProgressBar');
        steps.forEach(function(s) { var el = document.getElementById(s); el.classList.remove('active', 'done'); });
        bar.style.width = '0%';
        steps.forEach(function(stepId, i) {
            setTimeout(function() {
                if (i > 0) { document.getElementById(steps[i - 1]).classList.remove('active'); document.getElementById(steps[i - 1]).classList.add('done'); }
                document.getElementById(stepId).classList.add('active');
                bar.style.width = ((i + 1) / steps.length * 100) + '%';
            }, i * 600);
        });
    }

    /* ===== FILTER SIDEBAR ===== */
    var allCards = document.querySelectorAll('.payment-card');

    // Open / Close
    document.getElementById('openFilterBtn').addEventListener('click', function() {
        document.getElementById('sfSidebar').classList.add('active');
        document.body.style.overflow = 'hidden';
    });
    function closeSidebar() {
        document.getElementById('sfSidebar').classList.remove('active');
        document.body.style.overflow = '';
    }
    document.getElementById('sfCloseBtn').addEventListener('click', closeSidebar);
    document.getElementById('sfOverlay').addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('sfSidebar').classList.contains('active')) closeSidebar();
    });

    // Sync quickSearch with sidebar search
    var qs = document.getElementById('quickSearchInput');
    if (qs) qs.addEventListener('input', function() {
        var sfInput = document.getElementById('sfSearchInput');
        if (sfInput) sfInput.value = qs.value;
        sfApply();
    });
    document.getElementById('sfSearchInput').addEventListener('input', function() {
        if (qs) qs.value = this.value;
        sfPreview();
    });

    // Quick amount range buttons
    document.querySelectorAll('[data-amount-range]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var active = this.classList.contains('active');
            document.querySelectorAll('[data-amount-range]').forEach(function(b) { b.classList.remove('active'); });
            if (!active) {
                this.classList.add('active');
                var parts = this.getAttribute('data-amount-range').split('-');
                document.getElementById('sfAmountMin').value = parts[0];
                document.getElementById('sfAmountMax').value = parts[1] < 999999999 ? parts[1] : '';
            } else {
                document.getElementById('sfAmountMin').value = '';
                document.getElementById('sfAmountMax').value = '';
            }
            sfPreview();
        });
    });

    // Quick date range buttons
    document.querySelectorAll('[data-date-range]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var active = this.classList.contains('active');
            document.querySelectorAll('[data-date-range]').forEach(function(b) { b.classList.remove('active'); });
            if (!active) {
                this.classList.add('active');
                var now = new Date();
                var from = '', to = now.toISOString().split('T')[0];
                switch (this.getAttribute('data-date-range')) {
                    case 'this_month':
                        from = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
                        break;
                    case 'last_30':
                        from = new Date(now.getTime() - 30*86400000).toISOString().split('T')[0];
                        break;
                    case 'this_year':
                        from = new Date(now.getFullYear(), 0, 1).toISOString().split('T')[0];
                        break;
                }
                document.getElementById('sfDateFrom').value = from;
                document.getElementById('sfDateTo').value = to;
            } else {
                document.getElementById('sfDateFrom').value = '';
                document.getElementById('sfDateTo').value = '';
            }
            sfPreview();
        });
    });

    // Live preview on other inputs
    ['sfAmountMin','sfAmountMax','sfDateFrom','sfDateTo','sfAgentSelect','sfCitySelect','sfSortSelect'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', sfPreview);
        if (el) el.addEventListener('change', sfPreview);
    });

    function getFilters() {
        var sf = {};
        sf.search = (document.getElementById('sfSearchInput').value || document.getElementById('quickSearchInput').value || '').toLowerCase().trim();
        sf.amountMin = parseFloat(document.getElementById('sfAmountMin').value) || 0;
        sf.amountMax = parseFloat(document.getElementById('sfAmountMax').value) || Infinity;
        sf.dateFrom = document.getElementById('sfDateFrom').value || '';
        sf.dateTo = document.getElementById('sfDateTo').value || '';
        sf.agent = document.getElementById('sfAgentSelect').value || '';
        sf.city = document.getElementById('sfCitySelect').value || '';
        sf.sort = document.getElementById('sfSortSelect').value || 'newest';
        return sf;
    }

    function countActiveFilters() {
        var n = 0;
        if (document.getElementById('sfSearchInput').value.trim()) n++;
        if (document.getElementById('sfAmountMin').value || document.getElementById('sfAmountMax').value) n++;
        if (document.getElementById('sfDateFrom').value || document.getElementById('sfDateTo').value) n++;
        if (document.getElementById('sfAgentSelect').value) n++;
        if (document.getElementById('sfCitySelect').value) n++;
        if (document.getElementById('sfSortSelect').value !== 'newest') n++;
        return n;
    }

    function updateBadge() {
        var n = countActiveFilters();
        var badge = document.getElementById('filterCountBadge');
        var pill = document.getElementById('sfActivePill');
        var pillText = document.getElementById('sfActivePillText');
        var btn = document.getElementById('openFilterBtn');
        badge.textContent = n;
        badge.style.display = n > 0 ? '' : 'none';
        if (n > 0) { pill.classList.add('show'); pillText.textContent = n + ' active'; btn.classList.add('filter-active'); }
        else { pill.classList.remove('show'); btn.classList.remove('filter-active'); }
    }

    function matchesFilters(d, sf) {
        // Search
        if (sf.search) {
            var searchable = [d.StreetAddress||'', d.City||'', d.Province||'', d.agent_first||'', d.agent_last||'', d.tenant_name||'', d.period_start||'', d.period_end||''].join(' ').toLowerCase();
            if (searchable.indexOf(sf.search) === -1) return false;
        }
        // Amount range
        var amount = parseFloat(d.payment_amount) || 0;
        if (amount < sf.amountMin || amount > sf.amountMax) return false;
        // Date range
        if (sf.dateFrom && d.submitted_at < sf.dateFrom) return false;
        if (sf.dateTo && d.submitted_at.substring(0,10) > sf.dateTo) return false;
        // Agent
        if (sf.agent && String(d.agent_id) !== sf.agent) return false;
        // City
        if (sf.city && (d.City||'').trim() !== sf.city) return false;
        return true;
    }

    function sortCards(cards, sortVal) {
        cards.sort(function(a, b) {
            try {
                var da = JSON.parse(a.getAttribute('data-payment'));
                var db = JSON.parse(b.getAttribute('data-payment'));
                switch (sortVal) {
                    case 'oldest':      return new Date(da.submitted_at) - new Date(db.submitted_at);
                    case 'newest':      return new Date(db.submitted_at) - new Date(da.submitted_at);
                    case 'amount_high': return parseFloat(db.payment_amount) - parseFloat(da.payment_amount);
                    case 'amount_low':  return parseFloat(da.payment_amount) - parseFloat(db.payment_amount);
                    case 'agent_az':    return (da.agent_first + ' ' + da.agent_last).localeCompare(db.agent_first + ' ' + db.agent_last);
                    default: return 0;
                }
            } catch(e) { return 0; }
        });
        return cards;
    }

    function sfPreview() {
        var sf = getFilters();
        var count = 0;
        allCards.forEach(function(card) {
            try {
                var d = JSON.parse(card.getAttribute('data-payment'));
                if (matchesFilters(d, sf)) count++;
            } catch(e) { count++; }
        });
        document.getElementById('sfResultsNum').textContent = count;
        updateBadge();
    }

    function sfApply() {
        var sf = getFilters();
        var grid = document.querySelector('.payments-grid');
        var visible = [];
        allCards.forEach(function(card) {
            try {
                var d = JSON.parse(card.getAttribute('data-payment'));
                if (matchesFilters(d, sf)) { card.style.display = ''; visible.push(card); }
                else card.style.display = 'none';
            } catch(e) { card.style.display = ''; visible.push(card); }
        });
        // Sort visible
        visible = sortCards(visible, sf.sort);
        if (grid) visible.forEach(function(c) { grid.appendChild(c); });
        document.getElementById('sfResultsNum').textContent = visible.length;
        updateBadge();
    }

    // Apply button
    document.getElementById('sfApplyBtn').addEventListener('click', function() {
        sfApply();
        closeSidebar();
    });

    // Reset button
    document.getElementById('sfResetBtn').addEventListener('click', function() {
        document.getElementById('sfSearchInput').value = '';
        if (qs) qs.value = '';
        document.getElementById('sfAmountMin').value = '';
        document.getElementById('sfAmountMax').value = '';
        document.getElementById('sfDateFrom').value = '';
        document.getElementById('sfDateTo').value = '';
        document.getElementById('sfAgentSelect').value = '';
        document.getElementById('sfCitySelect').value = '';
        document.getElementById('sfSortSelect').value = 'newest';
        document.querySelectorAll('.quick-filter-btn.active').forEach(function(b) { b.classList.remove('active'); });
        sfApply();
    });

    // Initial preview
    sfPreview();
    </script>

    <!-- SKELETON HYDRATION — Progressive Content Reveal -->
    <script>
    (function () {
        'use strict';

        var MIN_SKELETON_MS = 400;
        var skeletonStart = Date.now();

        function hydrate() {
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');

            if (!pc) return;
            if (!sk) {
                pc.style.cssText = 'display:block;opacity:1;';
                document.dispatchEvent(new Event('skeleton:hydrated'));
                return;
            }

            pc.style.display = 'block';
            pc.style.opacity = '0';

            requestAnimationFrame(function () {
                sk.style.transition = 'opacity 0.35s ease';
                sk.style.opacity    = '0';

                pc.style.transition = 'opacity 0.42s ease 0.1s';
                requestAnimationFrame(function () {
                    pc.style.opacity = '1';
                });
            });

            window.setTimeout(function () {
                if (sk && sk.parentNode) sk.parentNode.removeChild(sk);
                pc.style.transition = '';
                pc.style.opacity    = '';
                document.dispatchEvent(new Event('skeleton:hydrated'));
            }, 520);
        }

        function scheduleHydration() {
            var elapsed   = Date.now() - skeletonStart;
            var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);
            if (remaining > 0) {
                window.setTimeout(hydrate, remaining);
            } else {
                hydrate();
            }
        }

        if (document.readyState === 'complete') {
            scheduleHydration();
        } else {
            window.addEventListener('load', scheduleHydration);
        }

    }());
    </script>
</body>
</html>
