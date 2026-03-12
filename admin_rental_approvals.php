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

// Fetch all rental verifications with property + agent info + property image
$sql = "
    SELECT rv.*, 
           p.StreetAddress, p.City, p.Barangay, p.Province, p.Status AS property_status, p.PropertyType, p.ListingPrice,
           a.first_name AS agent_first, a.last_name AS agent_last, a.email AS agent_email,
           ra.first_name AS reviewer_first, ra.last_name AS reviewer_last,
           (SELECT COUNT(*) FROM rental_verification_documents WHERE verification_id = rv.verification_id) AS doc_count,
           (SELECT pi.PhotoURL FROM property_images pi WHERE pi.property_ID = p.property_ID ORDER BY pi.SortOrder ASC LIMIT 1) AS property_image
    FROM rental_verifications rv
    JOIN property p ON rv.property_id = p.property_ID
    JOIN accounts a ON rv.agent_id = a.account_id
    LEFT JOIN accounts ra ON rv.reviewed_by = ra.account_id
    ORDER BY rv.submitted_at DESC
";
$result = $conn->query($sql);
$verifications = [];
while ($row = $result->fetch_assoc()) {
    $verifications[] = $row;
}

// Enrich verifications with documents and property images for client-side rendering
$allVids = array_column($verifications, 'verification_id');
$allPids = array_unique(array_column($verifications, 'property_id'));

$docs_by_vid = [];
if (!empty($allVids)) {
    $ph = implode(',', array_fill(0, count($allVids), '?'));
    $types = str_repeat('i', count($allVids));
    $dstmt = $conn->prepare("SELECT * FROM rental_verification_documents WHERE verification_id IN ($ph) ORDER BY uploaded_at");
    $dstmt->bind_param($types, ...$allVids);
    $dstmt->execute();
    $dres = $dstmt->get_result();
    while ($dr = $dres->fetch_assoc()) { $docs_by_vid[$dr['verification_id']][] = $dr; }
    $dstmt->close();
}

$imgs_by_pid = [];
if (!empty($allPids)) {
    $pids = array_values($allPids);
    $ph = implode(',', array_fill(0, count($pids), '?'));
    $types = str_repeat('i', count($pids));
    $istmt = $conn->prepare("SELECT property_ID, PhotoURL FROM property_images WHERE property_ID IN ($ph) ORDER BY SortOrder ASC");
    $istmt->bind_param($types, ...$pids);
    $istmt->execute();
    $ires = $istmt->get_result();
    while ($ir = $ires->fetch_assoc()) { $imgs_by_pid[$ir['property_ID']][] = $ir['PhotoURL']; }
    $istmt->close();
}

foreach ($verifications as &$_v) {
    $_v['documents'] = $docs_by_vid[$_v['verification_id']] ?? [];
    $_v['property_images'] = $imgs_by_pid[$_v['property_id']] ?? [];
    $_v['submitted_at_fmt'] = $_v['submitted_at'] ? date('M j, Y g:i A', strtotime($_v['submitted_at'])) : '';
    $_v['reviewed_at_fmt'] = $_v['reviewed_at'] ? date('M j, Y g:i A', strtotime($_v['reviewed_at'])) : '';
    $_v['lease_start_fmt'] = $_v['lease_start_date'] ? date('M j, Y', strtotime($_v['lease_start_date'])) : '';
    $_v['lease_end_calc'] = $_v['lease_start_date'] ? date('M j, Y', strtotime($_v['lease_start_date'] . " + {$_v['lease_term_months']} months - 1 day")) : '';
}
unset($_v);

$pending  = array_filter($verifications, fn($v) => $v['status'] === 'Pending');
$approved = array_filter($verifications, fn($v) => $v['status'] === 'Approved');
$rejected = array_filter($verifications, fn($v) => $v['status'] === 'Rejected');

$status_counts = [
    'All'      => count($verifications),
    'Pending'  => count($pending),
    'Approved' => count($approved),
    'Rejected' => count($rejected),
];

$status_tabs = [
    'All'      => ['icon' => 'bi-layers',       'count' => $status_counts['All']],
    'Pending'  => ['icon' => 'bi-clock-history', 'count' => $status_counts['Pending']],
    'Approved' => ['icon' => 'bi-check-circle',  'count' => $status_counts['Approved']],
    'Rejected' => ['icon' => 'bi-x-circle',      'count' => $status_counts['Rejected']],
];

$active_status = $_GET['status'] ?? 'All';
if (!array_key_exists($active_status, $status_tabs)) $active_status = 'All';

$success_message = '';
$error_message   = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'approved': $success_message = 'Rental verification has been approved and lease created.'; break;
        case 'rejected': $success_message = 'Rental verification has been rejected.'; break;
    }
}
if (isset($_GET['error'])) {
    $error_message = match($_GET['error']) {
        'approve_failed' => 'Failed to approve rental verification.',
        'reject_failed'  => 'Failed to reject rental verification.',
        default          => 'An error occurred.',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="images/Logo.png" type="image/png">
    <link rel="shortcut icon" href="images/Logo.png" type="image/png">
    <title>Rental Approvals - Admin Panel</title>
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
        .kpi-card .kpi-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.125rem; }
        .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); }

        /* ===== STATUS TABS ===== */
        .rental-tabs { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; position: relative; }
        .rental-tabs::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .rental-tabs .nav-tabs { border: none; padding: 0 1rem; margin: 0; }
        .rental-tabs .nav-item { margin: 0; }
        .rental-tabs .nav-link { border: none; border-radius: 0; padding: 1rem 1.25rem; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); background: transparent; transition: all 0.2s ease; display: flex; align-items: center; gap: 0.5rem; border-bottom: 2px solid transparent; }
        .rental-tabs .nav-link:hover { color: var(--text-primary); background: rgba(37,99,235,0.03); }
        .rental-tabs .nav-link.active { color: var(--gold-dark); border-bottom-color: var(--gold); background: rgba(212,175,55,0.04); }
        .tab-badge { font-size: 0.7rem; padding: 0.15rem 0.5rem; border-radius: 10px; font-weight: 700; }
        .badge-all      { background: rgba(212,175,55,0.1); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.15); }
        .badge-pending  { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .badge-approved { background: rgba(34,197,94,0.1);  color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .badge-rejected { background: rgba(239,68,68,0.1);  color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }

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
        .rentals-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }

        /* ===== RENTAL CARD ===== */
        .rental-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; transition: all 0.3s ease; height: 100%; display: flex; flex-direction: column; position: relative; }
        .rental-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); opacity: 0; transition: opacity 0.3s ease; z-index: 5; }
        .rental-card:hover { border-color: rgba(37,99,235,0.25); box-shadow: 0 8px 32px rgba(37,99,235,0.08); transform: translateY(-4px); }
        .rental-card:hover::before { opacity: 1; }

        .card-img-wrap { position: relative; height: 180px; background: #f1f5f9; overflow: hidden; }
        .card-img-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .rental-card:hover .card-img-wrap img { transform: scale(1.05); }
        .card-img-wrap .img-overlay { position: absolute; bottom: 0; left: 0; right: 0; height: 60%; background: linear-gradient(to top, rgba(0,0,0,0.65) 0%, transparent 100%); pointer-events: none; }

        .card-img-wrap .type-badge { position: absolute; bottom: 12px; left: 14px; padding: 0.2rem 0.6rem; border-radius: 2px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 3; background: rgba(0,0,0,0.7); color: #e2e8f0; backdrop-filter: blur(4px); display: inline-flex; align-items: center; gap: 0.3rem; }
        .card-img-wrap .status-badge { position: absolute; top: 12px; right: 12px; display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.65rem; border-radius: 2px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 3; }
        .status-badge.pending  { background: rgba(245,158,11,0.9); color: #fff; }
        .status-badge.approved { background: rgba(34,197,94,0.9);  color: #fff; }
        .status-badge.rejected { background: rgba(239,68,68,0.9);  color: #fff; }

        .card-img-wrap .price-overlay { position: absolute; bottom: 12px; right: 14px; z-index: 3; }
        .card-img-wrap .price-overlay .price { font-size: 1.3rem; font-weight: 800; background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.5)); }

        .rental-card .card-body-content { padding: 1rem 1.25rem; flex: 1; display: flex; flex-direction: column; position: relative; z-index: 2; }
        .rental-card .prop-address { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
        .rental-card .prop-location { font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-bottom: 0.75rem; }
        .rental-card .prop-location i { color: var(--blue); font-size: 0.75rem; }

        .rental-meta-row { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
        .rental-meta-item { display: inline-flex; align-items: center; gap: 0.3rem; background: #f8fafc; padding: 0.2rem 0.55rem; border-radius: 2px; border: 1px solid #e2e8f0; font-size: 0.75rem; font-weight: 500; color: var(--text-secondary); }
        .rental-meta-item i { color: #94a3b8; font-size: 0.7rem; }
        .rental-meta-item.agent-meta i { color: var(--blue); }
        .rental-meta-item.tenant-meta i { color: var(--gold-dark); }
        .rental-meta-item.date-meta i { color: var(--gold-dark); }
        .rental-meta-item.docs-meta i { color: #16a34a; }

        .rental-card .card-footer-section { margin-top: auto; padding-top: 0.75rem; border-top: 1px solid #e2e8f0; }
        .rental-card .btn-manage { display: flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%; background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 100%); color: #fff; border: none; padding: 0.6rem; font-size: 0.8rem; font-weight: 700; border-radius: 4px; cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(37,99,235,0.2); }
        .rental-card .btn-manage:hover { box-shadow: 0 4px 16px rgba(37,99,235,0.3); transform: translateY(-1px); }

        .rental-card .pending-actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
        .pending-actions .btn-approve-sm, .pending-actions .btn-reject-sm { flex: 1; padding: 0.45rem; font-size: 0.75rem; font-weight: 700; border: none; border-radius: 3px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 0.3rem; text-transform: uppercase; letter-spacing: 0.03em; }
        .btn-approve-sm { background: rgba(34,197,94,0.12); color: #16a34a; border: 1px solid rgba(34,197,94,0.2) !important; }
        .btn-approve-sm:hover { background: #22c55e; color: #fff; }
        .btn-reject-sm { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.18) !important; }
        .btn-reject-sm:hover { background: #ef4444; color: #fff; }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 4rem 2rem; background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; }
        .empty-state i { font-size: 3rem; color: var(--text-secondary); opacity: 0.3; margin-bottom: 0.75rem; display: block; }
        .empty-state h4 { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; }
        .empty-state p { color: var(--text-secondary); margin: 0; }

        /* ===== MODAL OVERLAY & CONTAINER ===== */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; z-index: 1050; opacity: 0; transition: opacity 0.25s ease; backdrop-filter: blur(2px); }
        .modal-overlay.show, .modal-overlay.is-closing { display: flex; align-items: center; justify-content: center; }
        .modal-overlay.show { opacity: 1; }
        .modal-container { background: var(--card-bg); border-radius: 6px; box-shadow: 0 20px 60px rgba(0,0,0,0.18); max-width: 820px; width: 92%; max-height: 92vh; overflow-y: auto; transform: scale(0.96) translateY(8px); opacity: 0; transition: opacity 0.25s cubic-bezier(0.16,1,0.3,1), transform 0.25s cubic-bezier(0.16,1,0.3,1); border: 1px solid rgba(37,99,235,0.12); }
        .modal-large { max-width: 1100px; width: 96%; }
        .modal-overlay.show .modal-container { opacity: 1; transform: scale(1) translateY(0); }
        /* --- Smooth close keyframes --- */
        @keyframes modal-overlay-out   { from { opacity: 1; } to { opacity: 0; } }
        @keyframes modal-container-out { from { opacity: 1; transform: scale(1) translateY(0); } to { opacity: 0; transform: scale(0.96) translateY(8px); } }
        .modal-overlay.is-closing                     { animation: modal-overlay-out 0.25s ease forwards; pointer-events: none; }
        .modal-overlay.is-closing .modal-container    { animation: modal-container-out 0.2s cubic-bezier(0.55,0,0.85,0.35) forwards; }
        .modal-container::-webkit-scrollbar { width: 5px; }
        .modal-container::-webkit-scrollbar-track { background: #f1f1f1; }
        .modal-container::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.4); border-radius: 4px; }

        .modal-admin-header { background: var(--card-bg); padding: 1.25rem 1.75rem; border-bottom: 1px solid rgba(37,99,235,0.1); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 10; }
        .modal-admin-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent 0%, var(--gold) 30%, var(--blue) 70%, transparent 100%); }
        .modal-admin-header h2 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .modal-admin-header h2 i { color: var(--gold-dark); }
        .modal-header-meta { display: flex; align-items: center; gap: 0.75rem; }
        .modal-vid-badge { font-size: 0.7rem; font-weight: 700; background: rgba(212,175,55,0.1); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); padding: 0.2rem 0.6rem; border-radius: 2px; letter-spacing: 0.5px; }
        .modal-close-btn { background: none; border: 1px solid rgba(37,99,235,0.12); width: 32px; height: 32px; border-radius: 4px; font-size: 1.1rem; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
        .modal-close-btn:hover { background: rgba(239,68,68,0.08); color: #ef4444; border-color: rgba(239,68,68,0.25); }
        .modal-body { padding: 0; }
        .modal-footer { padding: 1rem 1.75rem; background: rgba(37,99,235,0.02); border-top: 1px solid rgba(37,99,235,0.08); display: flex; gap: 0.6rem; justify-content: flex-end; align-items: center; }
        .btn-modal { padding: 0.55rem 1.35rem; font-size: 0.83rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-modal:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
        .btn-modal-secondary { background: rgba(37,99,235,0.07); color: var(--text-secondary); border: 1px solid rgba(37,99,235,0.1); }
        .btn-modal-secondary:hover { background: rgba(37,99,235,0.14); color: var(--text-primary); }
        .btn-modal-success { background: #22c55e; color: #fff; }
        .btn-modal-success:hover { background: #16a34a; }
        .btn-modal-danger { background: #ef4444; color: #fff; }
        .btn-modal-danger:hover { background: #dc2626; }

        /* ===== RVD: RENTAL VERIFICATION DETAILS MODAL ===== */
        .rvd-hero { position: relative; height: 260px; overflow: hidden; background: linear-gradient(135deg, #1a1a2e, #16213e); }
        .rvd-hero-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
        .rvd-hero:hover .rvd-hero-img { transform: scale(1.02); }
        .rvd-hero-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.65) 100%); }
        .rvd-hero-no-img { width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: rgba(255,255,255,0.2); gap: 0.5rem; }
        .rvd-hero-no-img i { font-size: 3.5rem; }
        .rvd-hero-no-img span { font-size: 0.8rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; }
        .rvd-hero-content { position: absolute; bottom: 0; left: 0; right: 0; padding: 1rem 1.5rem; z-index: 2; }
        .rvd-hero-address { font-size: 1.15rem; font-weight: 800; color: #fff; text-shadow: 0 1px 4px rgba(0,0,0,0.4); margin-bottom: 0.2rem; line-height: 1.3; }
        .rvd-hero-city { font-size: 0.8rem; color: rgba(255,255,255,0.75); display: flex; align-items: center; gap: 0.3rem; }
        .rvd-hero-top { position: absolute; top: 0.85rem; left: 1rem; right: 1rem; display: flex; justify-content: space-between; align-items: flex-start; z-index: 2; }
        .rvd-type-badge { background: rgba(255,255,255,0.92); color: var(--text-primary); padding: 0.28rem 0.65rem; border-radius: 3px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.5); }
        .rvd-status-hero { padding: 0.28rem 0.75rem; border-radius: 3px; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .rvd-status-hero.pending  { background: rgba(245,158,11,0.9); color: #fff; }
        .rvd-status-hero.approved { background: rgba(34,197,94,0.9);  color: #fff; }
        .rvd-status-hero.rejected { background: rgba(239,68,68,0.9);  color: #fff; }
        .rvd-hero-dots { position: absolute; bottom: 3.5rem; right: 1.25rem; display: flex; gap: 0.35rem; z-index: 3; }
        .rvd-hero-dot { width: 7px; height: 7px; border-radius: 50%; border: none; background: rgba(255,255,255,0.4); cursor: pointer; transition: all 0.15s; padding: 0; }
        .rvd-hero-dot.active { background: var(--gold); transform: scale(1.3); }
        .rvd-gallery-prev, .rvd-gallery-next { position: absolute; top: 50%; transform: translateY(-50%); z-index: 3; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: #fff; width: 34px; height: 34px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; transition: all 0.15s; backdrop-filter: blur(4px); }
        .rvd-gallery-prev { left: 0.75rem; }
        .rvd-gallery-next { right: 0.75rem; }
        .rvd-gallery-prev:hover, .rvd-gallery-next:hover { background: rgba(212,175,55,0.8); border-color: var(--gold); }
        .rvd-gallery-prev:disabled, .rvd-gallery-next:disabled { opacity: 0.3; cursor: not-allowed; }
        .rvd-gallery-counter { position: absolute; top: 0.85rem; left: 50%; transform: translateX(-50%); z-index: 3; background: rgba(0,0,0,0.45); color: rgba(255,255,255,0.9); font-size: 0.7rem; font-weight: 600; padding: 0.2rem 0.55rem; border-radius: 10px; letter-spacing: 0.3px; backdrop-filter: blur(4px); display: none; }

        /* RVD: Stat Strip */
        .rvd-stat-strip { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1px solid rgba(37,99,235,0.08); }
        .rvd-stat { padding: 1rem 1.25rem; text-align: center; position: relative; border-right: 1px solid rgba(37,99,235,0.06); transition: background 0.15s; }
        .rvd-stat:last-child { border-right: none; }
        .rvd-stat:hover { background: rgba(212,175,55,0.03); }
        .rvd-stat-label { font-size: 0.62rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.3rem; }
        .rvd-stat-value { font-size: 0.95rem; font-weight: 800; color: var(--text-primary); }
        .rvd-stat-value.gold  { color: var(--gold-dark); font-size: 1.05rem; }
        .rvd-stat-value.green { color: #16a34a; }
        .rvd-stat-value.blue  { color: var(--blue); }
        .rvd-stat-sub { font-size: 0.65rem; color: var(--text-secondary); margin-top: 0.1rem; }

        /* RVD: Body Sections */
        .rvd-body { padding: 1.5rem; }
        .rvd-section { margin-bottom: 1.5rem; }
        .rvd-section:last-child { margin-bottom: 0; }
        .rvd-section-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--gold-dark); margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid rgba(212,175,55,0.15); position: relative; }
        .rvd-section-title::before { content: ''; position: absolute; bottom: -1px; left: 0; width: 32px; height: 2px; background: var(--gold); border-radius: 1px; }
        .rvd-section-title i { font-size: 0.85rem; }

        /* RVD: Two-column panels */
        .rvd-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .rvd-panel { background: #fafbfe; border: 1px solid rgba(37,99,235,0.07); border-radius: 5px; padding: 1rem 1.25rem; }
        .rvd-panel-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem; }
        .rvd-panel-title.tenant { color: var(--gold-dark); }
        .rvd-panel-title.blue   { color: var(--blue); }
        .rvd-row { display: flex; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.55rem; }
        .rvd-row:last-child { margin-bottom: 0; }
        .rvd-row-icon { width: 18px; font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.1rem; flex-shrink: 0; text-align: center; }
        .rvd-row-label { font-size: 0.68rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; min-width: 68px; flex-shrink: 0; margin-top: 0.1rem; }
        .rvd-row-value { font-size: 0.82rem; color: var(--text-primary); font-weight: 500; word-break: break-word; }
        .rvd-row-value.strong { font-weight: 700; }
        .rvd-email-link { color: var(--blue); text-decoration: none; }
        .rvd-email-link:hover { text-decoration: underline; }

        /* RVD: Detail grid */
        .rvd-detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
        .rvd-detail-cell { background: #fafbfe; border: 1px solid rgba(37,99,235,0.07); border-radius: 5px; padding: 0.75rem 1rem; }
        .rvd-detail-cell .rvd-cell-label { font-size: 0.62rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.25rem; }
        .rvd-detail-cell .rvd-cell-value { font-size: 0.88rem; color: var(--text-primary); font-weight: 600; }
        .rvd-detail-cell .rvd-cell-value.gold  { color: var(--gold-dark); font-size: 1rem; }
        .rvd-detail-cell .rvd-cell-value.muted { font-weight: 400; color: var(--text-secondary); }

        /* RVD: Status pill */
        .rvd-status-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
        .rvd-status-pill.pending  { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.2); }
        .rvd-status-pill.approved { background: rgba(34,197,94,0.1);  color: #16a34a; border: 1px solid rgba(34,197,94,0.2); }
        .rvd-status-pill.rejected { background: rgba(239,68,68,0.1);  color: #dc2626; border: 1px solid rgba(239,68,68,0.2); }
        .rvd-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
        .rvd-dot.pending  { background: #d97706; }
        .rvd-dot.approved { background: #16a34a; }
        .rvd-dot.rejected { background: #dc2626; }

        /* RVD: Notes / Rejection box */
        .rvd-rejection-box { background: rgba(239,68,68,0.04); border: 1px solid rgba(239,68,68,0.12); border-left: 3px solid #ef4444; padding: 0.85rem 1.1rem; border-radius: 4px; }
        .rvd-rejection-box .rvd-rej-title { font-size: 0.65rem; font-weight: 700; color: #ef4444; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.35rem; }
        .rvd-rejection-box .rvd-rej-text { font-size: 0.85rem; color: #7f1d1d; line-height: 1.55; }
        .rvd-notes-box { background: rgba(37,99,235,0.03); border: 1px solid rgba(37,99,235,0.1); border-left: 3px solid var(--blue); padding: 0.85rem 1.1rem; border-radius: 4px; }
        .rvd-notes-box .rvd-notes-title { font-size: 0.65rem; font-weight: 700; color: var(--blue); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.35rem; }
        .rvd-notes-box .rvd-notes-text { font-size: 0.85rem; color: var(--text-primary); line-height: 1.55; white-space: pre-wrap; }

        /* RVD: Documents */
        .rvd-doc-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .rvd-doc-item { display: flex; align-items: center; gap: 0.85rem; padding: 0.75rem 1rem; background: #fafbfe; border-radius: 5px; border: 1px solid rgba(37,99,235,0.07); transition: border-color 0.15s, background 0.15s; }
        .rvd-doc-item:hover { border-color: rgba(212,175,55,0.25); background: rgba(212,175,55,0.02); }
        .rvd-doc-icon-wrap { width: 40px; height: 40px; border-radius: 5px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
        .rvd-doc-icon-wrap.rvd-pdf  { background: rgba(239,68,68,0.08);  color: #dc2626; border: 1px solid rgba(239,68,68,0.12); }
        .rvd-doc-icon-wrap.rvd-img  { background: rgba(37,99,235,0.07);  color: var(--blue); border: 1px solid rgba(37,99,235,0.12); }
        .rvd-doc-icon-wrap.rvd-word { background: rgba(37,99,235,0.08);  color: #1d4ed8; border: 1px solid rgba(37,99,235,0.15); }
        .rvd-doc-icon-wrap.rvd-file { background: rgba(107,114,128,0.08); color: #6b7280; border: 1px solid rgba(107,114,128,0.12); }
        .rvd-doc-info { flex: 1; min-width: 0; }
        .rvd-doc-name { font-size: 0.83rem; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rvd-doc-meta { font-size: 0.68rem; color: var(--text-secondary); margin-top: 0.1rem; }
        .rvd-doc-actions { display: flex; gap: 0.35rem; flex-shrink: 0; }
        .rvd-btn-doc { padding: 0.3rem 0.6rem; font-size: 0.7rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 0.25rem; }
        .rvd-btn-doc.rvd-download { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .rvd-btn-doc.rvd-download:hover { background: var(--blue); color: #fff; }

        /* RVD: Timeline */
        .rvd-timeline { display: flex; flex-direction: column; gap: 0; }
        .rvd-tl-item { display: flex; align-items: flex-start; gap: 0.85rem; padding: 0.65rem 0; position: relative; }
        .rvd-tl-item:not(:last-child)::after { content: ''; position: absolute; left: 10px; top: 2rem; bottom: -0.65rem; width: 1px; background: rgba(37,99,235,0.12); }
        .rvd-tl-dot { width: 21px; height: 21px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; flex-shrink: 0; z-index: 1; }
        .rvd-tl-dot.gold  { background: rgba(212,175,55,0.15); color: var(--gold-dark); border: 1.5px solid rgba(212,175,55,0.35); }
        .rvd-tl-dot.green { background: rgba(34,197,94,0.12); color: #16a34a; border: 1.5px solid rgba(34,197,94,0.3); }
        .rvd-tl-dot.red   { background: rgba(239,68,68,0.1); color: #dc2626; border: 1.5px solid rgba(239,68,68,0.3); }
        .rvd-tl-dot.gray  { background: rgba(107,114,128,0.1); color: #6b7280; border: 1.5px solid rgba(107,114,128,0.2); }
        .rvd-tl-content .rvd-tl-event { font-size: 0.8rem; font-weight: 600; color: var(--text-primary); }
        .rvd-tl-content .rvd-tl-time  { font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.1rem; }

        /* RVD: Responsive */
        @media (max-width: 768px) {
            .rvd-stat-strip { grid-template-columns: repeat(2,1fr); }
            .rvd-two-col { grid-template-columns: 1fr; }
            .rvd-hero { height: 200px; }
            .rvd-detail-grid { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 480px) {
            .rvd-stat-strip { grid-template-columns: repeat(2,1fr); }
            .rvd-detail-grid { grid-template-columns: 1fr; }
        }

        /* ===== Approve Modal (fsm-) ===== */
        .fsm-overlay .modal-dialog { max-width: 580px; }
        .fsm-shell { border-radius: 6px; overflow: hidden; border: 1px solid rgba(37,99,235,0.1); box-shadow: 0 20px 60px rgba(0,0,0,0.18); }
        .fsm-header { display: flex; align-items: center; gap: 0.85rem; padding: 1.25rem 1.5rem; background: var(--card-bg); border-bottom: 1px solid rgba(37,99,235,0.08); position: relative; }
        .fsm-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent 0%, #22c55e 30%, #16a34a 70%, transparent 100%); }
        .fsm-header-icon { width: 42px; height: 42px; border-radius: 8px; background: rgba(34,197,94,0.1); color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .fsm-header-text { flex: 1; min-width: 0; }
        .fsm-header-title { font-size: 1.05rem; font-weight: 800; color: var(--text-primary); margin: 0; }
        .fsm-header-sub { font-size: 0.78rem; color: var(--text-secondary); margin: 0; }
        .fsm-close { background: none; border: 1px solid rgba(37,99,235,0.12); width: 32px; height: 32px; border-radius: 4px; font-size: 1.1rem; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .15s; flex-shrink: 0; }
        .fsm-close:hover { background: rgba(239,68,68,0.08); color: #ef4444; border-color: rgba(239,68,68,0.25); }
        .fsm-body { padding: 1.5rem; background: #fafbfe; }
        .fsm-field { margin-bottom: 1rem; }
        .fsm-field:last-child { margin-bottom: 0; }
        .fsm-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.45rem; display: flex; align-items: center; gap: 0.4rem; }
        .fsm-label i { color: var(--gold-dark); font-size: 0.8rem; }
        .fsm-req { color: #ef4444; }
        .fsm-input { width: 100%; padding: 0.6rem 0.85rem; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.88rem; font-weight: 500; color: var(--text-primary); background: #fff; transition: all 0.2s; }
        .fsm-input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); outline: none; }
        .fsm-textarea { min-height: 70px; resize: vertical; }
        .fsm-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        .fsm-suffix-wrap { position: relative; }
        .fsm-suffix-wrap .fsm-input { padding-right: 2.2rem; }
        .fsm-suffix { position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--gold-dark); font-weight: 800; font-size: 0.82rem; pointer-events: none; }
        .fsm-divider { height: 1px; background: #e2e8f0; margin: 0.5rem 0 1rem; }
        .fsm-comm-preview { background: rgba(34,197,94,0.04); border: 1px solid rgba(34,197,94,0.15); border-radius: 4px; padding: 0.75rem 1rem; display: flex; align-items: center; justify-content: space-between; }
        .fsm-comm-preview-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #16a34a; display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.2rem; }
        .fsm-comm-preview-val { font-size: 1.15rem; font-weight: 900; color: #16a34a; }
        .fsm-dim { opacity: 0.4; }
        .fsm-alert { background: rgba(34,197,94,0.06); border: 1px solid rgba(34,197,94,0.15); border-radius: 4px; padding: 0.75rem 1rem; font-size: 0.82rem; color: #065f46; display: flex; align-items: flex-start; gap: 0.55rem; margin-bottom: 1rem; }
        .fsm-alert i { color: #16a34a; margin-top: 0.1rem; flex-shrink: 0; }
        .fsm-footer { padding: 1rem 1.5rem; background: var(--card-bg); border-top: 1px solid rgba(37,99,235,0.08); display: flex; gap: 0.6rem; justify-content: flex-end; }
        .fsm-btn { padding: 0.55rem 1.3rem; font-size: 0.83rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .fsm-btn-cancel { background: rgba(37,99,235,0.07); color: var(--text-secondary); border: 1px solid rgba(37,99,235,0.1); }
        .fsm-btn-cancel:hover { background: rgba(37,99,235,0.14); color: var(--text-primary); }
        .fsm-btn-save { background: #22c55e; color: #fff; }
        .fsm-btn-save:hover { background: #16a34a; box-shadow: 0 4px 12px rgba(34,197,94,0.25); }

        /* ===== Rejection Modal (rjm-) ===== */
        .rjm-container { background: var(--card-bg); border-radius: 6px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); max-width: 500px; width: 92%; position: relative; overflow: hidden; text-align: center; padding: 0; }
        .rjm-top-bar { position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent, #ef4444, #dc2626, transparent); }
        .cfd-close-btn { position: absolute; top: 0.75rem; right: 0.75rem; background: none; border: 1px solid rgba(37,99,235,0.12); width: 28px; height: 28px; border-radius: 4px; font-size: 0.9rem; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
        .cfd-close-btn:hover { background: rgba(239,68,68,0.08); color: #ef4444; border-color: rgba(239,68,68,0.25); }
        .rjm-icon-wrap { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin: 2rem auto 0.75rem; background: rgba(239,68,68,0.1); color: #dc2626; border: 2px solid rgba(239,68,68,0.2); }
        .rjm-title { font-size: 1.1rem; font-weight: 800; color: var(--text-primary); }
        .rjm-subtitle { font-size: 0.82rem; color: var(--text-secondary); margin: 0.25rem 1.5rem 1.25rem; }
        .rjm-body { padding: 0 1.75rem 1.25rem; text-align: left; }
        .rjm-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 0.45rem; display: flex; align-items: center; gap: 0.35rem; }
        .rjm-textarea { width: 100%; min-height: 90px; padding: 0.65rem 0.85rem; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.88rem; font-weight: 500; color: var(--text-primary); background: #fff; resize: vertical; transition: all 0.2s; }
        .rjm-textarea:focus { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.08); outline: none; }
        .rjm-error { color: #dc2626; font-size: 0.78rem; font-weight: 600; margin-top: 0.35rem; display: flex; align-items: center; gap: 0.3rem; }
        .rjm-footer { padding: 1rem 1.75rem; display: flex; gap: 0.55rem; justify-content: center; }
        .cfd-btn { padding: 0.55rem 1.35rem; font-size: 0.83rem; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .cfd-cancel { background: rgba(37,99,235,0.07); color: var(--text-secondary); border: 1px solid rgba(37,99,235,0.1); }
        .cfd-cancel:hover { background: rgba(37,99,235,0.14); color: var(--text-primary); }
        .cfd-danger { background: #ef4444; color: #fff; }
        .cfd-danger:hover { background: #dc2626; }

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
        .sk-rentals-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; padding: 1.5rem; }
        .sk-rental-card { background: #fff; border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; }
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
            .rental-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
            .rental-tabs .nav-link { white-space: nowrap; padding: 0.75rem 0.85rem; font-size: 0.8rem; }
            .rentals-grid { grid-template-columns: 1fr; }
            .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .sk-rentals-grid { grid-template-columns: 1fr; }
            .modal-container { width: 98%; }
        }
        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .rental-tabs .nav-link { padding: 0.6rem 0.7rem; font-size: 0.75rem; }
            .sk-kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        }
    </style>
</head>
<body>
    <?php
    $active_page = 'admin_rental_approvals.php';
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
                <div class="sk-rentals-grid">
                    <div class="sk-rental-card"><div class="sk-card-img sk-shimmer"></div><div class="sk-card-body"><div class="sk-line sk-shimmer" style="width:82%;height:16px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:52%;height:12px;margin-bottom:12px;"></div><div style="display:flex;gap:6px;margin-bottom:12px;"><div class="sk-shimmer" style="width:82px;height:10px;border-radius:3px;"></div><div class="sk-shimmer" style="width:95px;height:10px;border-radius:3px;"></div></div></div><div class="sk-card-footer"><div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div></div></div>
                    <div class="sk-rental-card"><div class="sk-card-img sk-shimmer"></div><div class="sk-card-body"><div class="sk-line sk-shimmer" style="width:75%;height:16px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:45%;height:12px;margin-bottom:12px;"></div><div style="display:flex;gap:6px;margin-bottom:12px;"><div class="sk-shimmer" style="width:78px;height:10px;border-radius:3px;"></div><div class="sk-shimmer" style="width:88px;height:10px;border-radius:3px;"></div></div></div><div class="sk-card-footer"><div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div></div></div>
                    <div class="sk-rental-card"><div class="sk-card-img sk-shimmer"></div><div class="sk-card-body"><div class="sk-line sk-shimmer" style="width:88%;height:16px;margin-bottom:8px;"></div><div class="sk-line sk-shimmer" style="width:58%;height:12px;margin-bottom:12px;"></div><div style="display:flex;gap:6px;margin-bottom:12px;"><div class="sk-shimmer" style="width:85px;height:10px;border-radius:3px;"></div><div class="sk-shimmer" style="width:92px;height:10px;border-radius:3px;"></div></div></div><div class="sk-card-footer"><div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div></div></div>
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
                        '<?= $status_counts['Pending'] === 1 ? "1 Pending Rental Approval" : $status_counts['Pending'] . " Pending Rental Approvals" ?>',
                        '<?= $status_counts['Pending'] === 1
                            ? "1 rental verification is awaiting your review."
                            : $status_counts['Pending'] . " rental verifications are awaiting your review." ?>',
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
                    <h1>Rental Approvals</h1>
                    <p class="subtitle">Review and approve rental verification submissions from agents</p>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="fas fa-layer-group"></i></div>
                <div><div class="kpi-label">Total Submissions</div><div class="kpi-value"><?= $status_counts['All'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fas fa-clock"></i></div>
                <div><div class="kpi-label">Pending Review</div><div class="kpi-value"><?= $status_counts['Pending'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fas fa-check-circle"></i></div>
                <div><div class="kpi-label">Approved (Rented)</div><div class="kpi-value"><?= $status_counts['Approved'] ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="fas fa-times-circle"></i></div>
                <div><div class="kpi-label">Rejected</div><div class="kpi-value"><?= $status_counts['Rejected'] ?></div></div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div class="action-bar-left">
                <div class="action-search-wrap">
                    <i class="bi bi-search ab-search-icon"></i>
                    <input type="text" id="quickSearchInput" placeholder="Search address, city, tenant or agent…" autocomplete="off">
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
        <div class="rental-tabs">
            <ul class="nav nav-tabs">
                <?php foreach ($status_tabs as $tabKey => $tabInfo): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $active_status === $tabKey ? 'active' : '' ?>"
                           href="?status=<?= $tabKey ?>"
                           data-tab="<?= $tabKey ?>">
                            <i class="bi <?= $tabInfo['icon'] ?>"></i>
                            <?= $tabKey === 'Approved' ? 'Rented' : $tabKey ?>
                            <span class="tab-badge badge-<?= strtolower($tabKey) ?>"><?= $tabInfo['count'] ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="tab-content">
                <?php
                    $display = $active_status === 'All'
                        ? $verifications
                        : array_filter($verifications, fn($v) => $v['status'] === $active_status);
                ?>
                <?php if (empty($display)): ?>
                    <div class="empty-state">
                        <i class="bi bi-house-door"></i>
                        <h4>No <?= $active_status === 'All' ? '' : $active_status ?> Verifications</h4>
                        <p>There are no <?= strtolower($active_status) ?> rental verifications to display.</p>
                    </div>
                <?php else: ?>
                    <div class="rentals-grid">
                        <?php foreach ($display as $v): ?>
                            <div class="rental-card" data-verification='<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>'>
                                <div class="card-img-wrap">
                                    <?php if (!empty($v['property_image'])): ?>
                                        <img src="<?= htmlspecialchars($v['property_image']) ?>" alt="Property" onerror="this.src='uploads/default-property.jpg'">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#adb5bd;"><i class="bi bi-image" style="font-size:2.5rem;"></i></div>
                                    <?php endif; ?>
                                    <div class="img-overlay"></div>
                                    <div class="type-badge"><i class="bi bi-house-door"></i> <?= htmlspecialchars($v['PropertyType'] ?? 'Rental') ?></div>
                                    <?php $badgeClass = strtolower($v['status']); ?>
                                    <div class="status-badge <?= $badgeClass ?>">
                                        <i class="bi bi-circle-fill" style="font-size:0.35rem;"></i>
                                        <?= $v['status'] ?>
                                    </div>
                                    <div class="price-overlay">
                                        <div class="price">&#8369;<?= number_format($v['monthly_rent'], 0) ?>/mo</div>
                                    </div>
                                </div>

                                <div class="card-body-content">
                                    <h3 class="prop-address" title="<?= htmlspecialchars($v['StreetAddress']) ?>"><?= htmlspecialchars($v['StreetAddress']) ?></h3>
                                    <div class="prop-location"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($v['City'] . ', ' . $v['Province']) ?></div>

                                    <div class="rental-meta-row">
                                        <span class="rental-meta-item agent-meta"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($v['agent_first'] . ' ' . $v['agent_last']) ?></span>
                                        <span class="rental-meta-item tenant-meta"><i class="bi bi-person"></i> <?= htmlspecialchars($v['tenant_name']) ?></span>
                                        <span class="rental-meta-item"><i class="bi bi-calendar3"></i> <?= $v['lease_term_months'] ?> months</span>
                                        <span class="rental-meta-item docs-meta"><i class="bi bi-file-earmark-text"></i> <?= $v['doc_count'] ?> docs</span>
                                        <span class="rental-meta-item date-meta"><i class="bi bi-clock"></i> <?= date('M d, Y', strtotime($v['submitted_at'])) ?></span>
                                    </div>

                                    <div class="card-footer-section">
                                        <button class="btn-manage" onclick="viewRentalDetails(<?= $v['verification_id'] ?>)">
                                            <i class="bi bi-eye"></i> View Details
                                        </button>
                                        <?php if ($v['status'] === 'Pending'): ?>
                                            <div class="pending-actions">
                                                <button class="btn-approve-sm" onclick="openApproveModal(<?= $v['verification_id'] ?>, <?= $v['property_id'] ?>, <?= $v['agent_id'] ?>)">
                                                    <i class="bi bi-check-lg"></i> Approve
                                                </button>
                                                <button class="btn-reject-sm" onclick="openRejectModal(<?= $v['verification_id'] ?>)">
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

    <!-- ===== View Details Modal ===== -->
    <div class="modal-overlay" id="detailsModal">
        <div class="modal-container modal-large">
            <div class="modal-admin-header">
                <h2><i class="bi bi-house-check"></i> Rental Verification Details</h2>
                <div class="modal-header-meta">
                    <span class="modal-vid-badge" id="modalVidBadge"></span>
                    <button class="modal-close-btn" onclick="closeModal('detailsModal')">&times;</button>
                </div>
            </div>
            <div class="modal-body" id="modalContent">
                <div style="text-align:center;padding:3rem;"><div class="spinner-border text-secondary"></div></div>
            </div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </div>

    <!-- ===== Approve Modal ===== -->
    <div class="modal fade fsm-overlay" id="approveModal" tabindex="-1" aria-labelledby="approveLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="fsm-shell modal-content">
                <div class="fsm-header">
                    <div class="fsm-header-icon"><i class="bi bi-check-circle"></i></div>
                    <div class="fsm-header-text">
                        <h5 class="fsm-header-title" id="approveLabel">Approve Rental &amp; Set Commission</h5>
                        <div class="fsm-header-sub">Approving will lock the property and create an active lease</div>
                    </div>
                    <button type="button" class="fsm-close" data-bs-dismiss="modal" aria-label="Close">&times;</button>
                </div>
                <form id="approveForm">
                    <div class="fsm-body">
                        <input type="hidden" name="verification_id" id="approve_verification_id">
                        <input type="hidden" name="property_id" id="approve_property_id">
                        <input type="hidden" name="agent_id" id="approve_agent_id">

                        <div class="fsm-alert">
                            <i class="bi bi-info-circle-fill"></i>
                            <span>Approving this rental will lock the property status and create an active lease record. The commission rate will be applied to each confirmed monthly rent payment.</span>
                        </div>

                        <div class="fsm-row-2">
                            <div class="fsm-field">
                                <label class="fsm-label" for="approve_commission_rate"><i class="bi bi-percent"></i> Commission Rate <span class="fsm-req">*</span></label>
                                <div class="fsm-suffix-wrap">
                                    <input type="number" step="0.01" min="0.01" max="100" class="fsm-input" id="approve_commission_rate" name="commission_rate"
                                           placeholder="e.g. 5" required>
                                    <span class="fsm-suffix">%</span>
                                </div>
                            </div>
                            <div class="fsm-field">
                                <div class="fsm-comm-preview" style="height:100%;">
                                    <div>
                                        <div class="fsm-comm-preview-label"><i class="bi bi-coin"></i> Per-Payment Commission</div>
                                        <div class="fsm-comm-preview-val fsm-dim" id="commPreviewVal">&mdash;</div>
                                    </div>
                                    <i class="bi bi-calculator" style="font-size:1.5rem;color:rgba(212,175,55,0.3);"></i>
                                </div>
                            </div>
                        </div>

                        <div class="fsm-divider"></div>

                        <div class="fsm-field">
                            <label class="fsm-label" for="approve_notes"><i class="bi bi-chat-left-text"></i> Admin Notes <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                            <textarea class="fsm-input fsm-textarea" id="approve_notes" name="admin_notes" placeholder="Any additional notes about this approval..." maxlength="2000"></textarea>
                        </div>
                    </div>
                    <div class="fsm-footer">
                        <button type="button" class="fsm-btn fsm-btn-cancel" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                        <button type="submit" class="fsm-btn fsm-btn-save" id="approveBtn"><i class="bi bi-check2-circle"></i> Approve &amp; Finalize</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== Reject Modal ===== -->
    <div class="modal-overlay" id="rejectModal">
        <div class="rjm-container">
            <div class="rjm-top-bar"></div>
            <button class="cfd-close-btn" onclick="closeModal('rejectModal')">&times;</button>
            <div class="rjm-icon-wrap"><i class="bi bi-x-octagon-fill"></i></div>
            <div class="rjm-title">Reject Rental Verification</div>
            <div class="rjm-subtitle">Provide a clear reason so the agent can understand and resubmit</div>
            <div class="rjm-body">
                <label class="rjm-label" for="reasonInput"><i class="bi bi-chat-left-text"></i> Rejection Reason</label>
                <textarea class="rjm-textarea" id="reasonInput" placeholder="Explain why this rental verification is being rejected..."></textarea>
                <div id="reasonError" class="rjm-error" style="display:none;"><i class="bi bi-exclamation-circle"></i> A reason is required.</div>
            </div>
            <div class="rjm-footer">
                <button class="cfd-btn cfd-cancel" onclick="closeModal('rejectModal')"><i class="bi bi-x-lg"></i> Cancel</button>
                <button class="cfd-btn cfd-danger" id="submitRejectBtn"><i class="bi bi-x-octagon"></i> Reject</button>
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
                    <div class="pc-icon-center"><i class="bi bi-house-check" id="pcIcon"></i></div>
                </div>
                <div class="pc-title" id="pcTitle">Processing Rental</div>
                <div class="pc-subtitle" id="pcSubtitle">Please wait&hellip;</div>
            </div>
            <div class="pc-steps-wrap">
                <div class="pc-steps">
                    <div class="pc-step" id="pcStep1"><div class="pc-step-dot"><i class="bi bi-check-lg"></i></div><span>Validating rental data</span></div>
                    <div class="pc-step" id="pcStep2"><div class="pc-step-dot"><i class="bi bi-check-lg"></i></div><span>Saving lease record</span></div>
                    <div class="pc-step" id="pcStep3"><div class="pc-step-dot"><i class="bi bi-check-lg"></i></div><span>Setting commission rate</span></div>
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
                <span class="sf-results-label">verifications match your filters</span>
            </div>

            <div class="sf-body">
                <!-- Search -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-search"></i> Search</div>
                    <div class="sf-search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" id="sfSearchInput" placeholder="Address, city, tenant name, agent name…">
                    </div>
                </div>

                <!-- Monthly Rent Range -->
                <div class="sf-section">
                    <div class="sf-section-title"><i class="bi bi-cash-stack"></i> Monthly Rent Range</div>
                    <div class="price-range-inputs">
                        <div class="price-input">
                            <span class="currency-sym">₱</span>
                            <input type="number" id="sfRentMin" placeholder="Min" min="0" step="1000">
                        </div>
                        <span class="range-divider">—</span>
                        <div class="price-input">
                            <span class="currency-sym">₱</span>
                            <input type="number" id="sfRentMax" placeholder="Max" min="0" step="1000">
                        </div>
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-rent-range="0-10000">Under 10K</button>
                        <button class="quick-filter-btn" data-rent-range="10000-30000">10K – 30K</button>
                        <button class="quick-filter-btn" data-rent-range="30000-60000">30K – 60K</button>
                        <button class="quick-filter-btn" data-rent-range="60000-999999999">60K+</button>
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
                        foreach ($verifications as $v) {
                            $aKey = $v['agent_id'];
                            if (!isset($agentSet[$aKey])) {
                                $agentSet[$aKey] = htmlspecialchars($v['agent_first'] . ' ' . $v['agent_last']);
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
                        foreach ($verifications as $v) {
                            $c = trim($v['City']);
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
                        <option value="rent_high">Rent: High → Low</option>
                        <option value="rent_low">Rent: Low → High</option>
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
        el.classList.remove('is-closing');
        el.style.display = 'flex';
        requestAnimationFrame(function() { el.classList.add('show'); });
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        var el = document.getElementById(id);
        if (!el || !el.classList.contains('show')) return;
        el.classList.remove('show');
        el.classList.add('is-closing');
        clearTimeout(_modalTimers[id]);
        _modalTimers[id] = setTimeout(function() {
            el.style.display = 'none';
            el.classList.remove('is-closing');
            if (!document.querySelector('.modal-overlay.show')) {
                document.body.style.overflow = '';
            }
        }, 250);
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

    /* ===== DATA ===== */
    var rentalVerifications = <?= json_encode($verifications, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    /* ===== UTILITY ===== */
    function rvdEsc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function rvdFormatSize(b) { b = Number(b) || 0; if (b < 1024) return b + ' B'; if (b < 1048576) return (b / 1024).toFixed(1) + ' KB'; return (b / 1048576).toFixed(1) + ' MB'; }

    /* ===== VIEW DETAILS ===== */
    function viewRentalDetails(vid) {
        var v = rentalVerifications.find(function(r) { return r.verification_id == vid; });
        if (!v) return;

        var stClass = v.status.toLowerCase();
        var stLabel = v.status === 'Approved' ? 'RENTED' : v.status.toUpperCase();
        var imgs = (v.property_images && v.property_images.length > 0) ? v.property_images : [];
        var totalImgs = imgs.length;

        // Update header badge
        document.getElementById('modalVidBadge').textContent = 'RV-' + v.verification_id;

        var rent = Number(v.monthly_rent) || 0;
        var deposit = Number(v.security_deposit) || 0;

        var html = '';

        // ── HERO ──
        html += '<div class="rvd-hero">';
        if (totalImgs > 0) {
            for (var i = 0; i < totalImgs; i++) {
                html += '<img src="' + imgs[i] + '" alt="" class="rvd-hero-img" id="rvd-img-' + i + '" style="position:absolute;inset:0;opacity:' + (i===0?1:0) + ';transition:opacity 0.4s;">';
            }
            if (totalImgs > 1) {
                html += '<button class="rvd-gallery-prev" id="rvdPrev" onclick="rvdGoPrev()"><i class="bi bi-chevron-left"></i></button>';
                html += '<button class="rvd-gallery-next" id="rvdNext" onclick="rvdGoNext()"><i class="bi bi-chevron-right"></i></button>';
                html += '<div class="rvd-hero-dots">';
                for (var d = 0; d < totalImgs; d++) {
                    html += '<button class="rvd-hero-dot ' + (d===0?'active':'') + '" id="rvd-dot-' + d + '" onclick="rvdGoTo(' + d + ')"></button>';
                }
                html += '</div>';
                html += '<div class="rvd-gallery-counter" id="rvdCounter" style="display:block;">1 / ' + totalImgs + '</div>';
            }
        } else {
            html += '<div class="rvd-hero-no-img"><i class="bi bi-image"></i><span>No Images</span></div>';
        }
        html += '<div class="rvd-hero-overlay"></div>';
        html += '<div class="rvd-hero-top">';
        html += '<span class="rvd-type-badge"><i class="bi bi-house-door me-1"></i>' + rvdEsc(v.PropertyType) + '</span>';
        html += '<span class="rvd-status-hero ' + stClass + '">' + stLabel + '</span>';
        html += '</div>';
        html += '<div class="rvd-hero-content">';
        html += '<div class="rvd-hero-address">' + rvdEsc(v.StreetAddress) + '</div>';
        html += '<div class="rvd-hero-city"><i class="bi bi-geo-alt-fill" style="color:var(--gold);"></i>' + rvdEsc(v.City) + ', ' + rvdEsc(v.Province) + '</div>';
        html += '</div>';
        html += '</div>';

        // ── STAT STRIP ──
        html += '<div class="rvd-stat-strip">';
        html += '<div class="rvd-stat"><div class="rvd-stat-label">Monthly Rent</div><div class="rvd-stat-value gold">\u20B1' + rent.toLocaleString() + '</div><div class="rvd-stat-sub">Per month</div></div>';
        html += '<div class="rvd-stat"><div class="rvd-stat-label">Security Deposit</div><div class="rvd-stat-value">\u20B1' + deposit.toLocaleString() + '</div><div class="rvd-stat-sub">Refundable</div></div>';
        html += '<div class="rvd-stat"><div class="rvd-stat-label">Lease Term</div><div class="rvd-stat-value blue">' + (v.lease_term_months || '\u2014') + ' months</div><div class="rvd-stat-sub">' + (v.lease_start_fmt || '\u2014') + '</div></div>';
        html += '<div class="rvd-stat"><div class="rvd-stat-label">Status</div><div class="rvd-stat-value" style="margin-top:0.15rem;"><span class="rvd-status-pill ' + stClass + '"><span class="rvd-dot ' + stClass + '"></span>' + stLabel + '</span></div></div>';
        html += '</div>';

        // ── BODY ──
        html += '<div class="rvd-body">';

        // Tenant + Agent two-col
        html += '<div class="rvd-section">';
        html += '<div class="rvd-section-title"><i class="bi bi-people-fill"></i> Parties Involved</div>';
        html += '<div class="rvd-two-col">';
        // Tenant panel
        html += '<div class="rvd-panel">';
        html += '<div class="rvd-panel-title tenant"><i class="bi bi-person-fill"></i> Tenant</div>';
        html += '<div class="rvd-row"><span class="rvd-row-icon"><i class="bi bi-person"></i></span><span class="rvd-row-label">Name</span><span class="rvd-row-value strong">' + rvdEsc(v.tenant_name) + '</span></div>';
        if (v.tenant_email) {
            html += '<div class="rvd-row"><span class="rvd-row-icon"><i class="bi bi-envelope"></i></span><span class="rvd-row-label">Email</span><span class="rvd-row-value"><a href="mailto:' + rvdEsc(v.tenant_email) + '" class="rvd-email-link">' + rvdEsc(v.tenant_email) + '</a></span></div>';
        } else {
            html += '<div class="rvd-row"><span class="rvd-row-icon"><i class="bi bi-envelope"></i></span><span class="rvd-row-label">Email</span><span class="rvd-row-value" style="color:var(--text-secondary);font-style:italic;">Not provided</span></div>';
        }
        if (v.tenant_phone) {
            html += '<div class="rvd-row"><span class="rvd-row-icon"><i class="bi bi-telephone"></i></span><span class="rvd-row-label">Phone</span><span class="rvd-row-value">' + rvdEsc(v.tenant_phone) + '</span></div>';
        }
        html += '</div>';
        // Agent panel
        html += '<div class="rvd-panel">';
        html += '<div class="rvd-panel-title blue"><i class="bi bi-person-badge-fill"></i> Agent</div>';
        html += '<div class="rvd-row"><span class="rvd-row-icon"><i class="bi bi-person-check"></i></span><span class="rvd-row-label">Name</span><span class="rvd-row-value strong">' + rvdEsc(v.agent_first) + ' ' + rvdEsc(v.agent_last) + '</span></div>';
        if (v.agent_email) {
            html += '<div class="rvd-row"><span class="rvd-row-icon"><i class="bi bi-envelope"></i></span><span class="rvd-row-label">Email</span><span class="rvd-row-value"><a href="mailto:' + rvdEsc(v.agent_email) + '" class="rvd-email-link">' + rvdEsc(v.agent_email) + '</a></span></div>';
        }
        html += '</div>';
        html += '</div>'; // close rvd-two-col
        html += '</div>'; // close rvd-section

        // Lease Details grid
        html += '<div class="rvd-section">';
        html += '<div class="rvd-section-title"><i class="bi bi-file-earmark-text"></i> Lease Details</div>';
        html += '<div class="rvd-detail-grid">';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Monthly Rent</div><div class="rvd-cell-value gold">\u20B1' + rent.toLocaleString(undefined,{minimumFractionDigits:2}) + '</div></div>';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Security Deposit</div><div class="rvd-cell-value">\u20B1' + deposit.toLocaleString(undefined,{minimumFractionDigits:2}) + '</div></div>';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Lease Start</div><div class="rvd-cell-value">' + rvdEsc(v.lease_start_fmt || '\u2014') + '</div></div>';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Lease Term</div><div class="rvd-cell-value">' + (v.lease_term_months || '\u2014') + ' months</div></div>';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Lease End (calc)</div><div class="rvd-cell-value">' + rvdEsc(v.lease_end_calc || '\u2014') + '</div></div>';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Property Type</div><div class="rvd-cell-value">' + rvdEsc(v.PropertyType) + '</div></div>';
        html += '</div>';
        html += '</div>';

        // Property info grid
        html += '<div class="rvd-section">';
        html += '<div class="rvd-section-title"><i class="bi bi-building"></i> Property Details</div>';
        html += '<div class="rvd-detail-grid">';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Address</div><div class="rvd-cell-value">' + rvdEsc(v.StreetAddress) + '</div></div>';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">City</div><div class="rvd-cell-value">' + rvdEsc(v.City) + '</div></div>';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Barangay</div><div class="rvd-cell-value">' + rvdEsc(v.Barangay || '\u2014') + '</div></div>';
        html += '<div class="rvd-detail-cell"><div class="rvd-cell-label">Property ID</div><div class="rvd-cell-value muted">#' + v.property_id + '</div></div>';
        html += '</div>';
        html += '</div>';

        // Additional notes
        if (v.additional_notes) {
            html += '<div class="rvd-section">';
            html += '<div class="rvd-section-title"><i class="bi bi-chat-square-text"></i> Additional Notes</div>';
            html += '<div class="rvd-notes-box"><div class="rvd-notes-title"><i class="bi bi-info-circle"></i> Notes</div><div class="rvd-notes-text">' + rvdEsc(v.additional_notes) + '</div></div>';
            html += '</div>';
        }

        // Admin notes — shown differently based on status
        if (v.admin_notes && v.status === 'Rejected') {
            html += '<div class="rvd-section">';
            html += '<div class="rvd-section-title"><i class="bi bi-x-circle"></i> Rejection Details</div>';
            html += '<div class="rvd-rejection-box"><div class="rvd-rej-title"><i class="bi bi-exclamation-triangle-fill"></i> Reason for Rejection</div><div class="rvd-rej-text">' + rvdEsc(v.admin_notes) + '</div></div>';
            html += '</div>';
        } else if (v.admin_notes && v.status === 'Approved') {
            html += '<div class="rvd-section">';
            html += '<div class="rvd-section-title"><i class="bi bi-check-circle"></i> Admin Notes</div>';
            html += '<div class="rvd-notes-box"><div class="rvd-notes-title"><i class="bi bi-chat-left-text"></i> Approval Notes</div><div class="rvd-notes-text">' + rvdEsc(v.admin_notes) + '</div></div>';
            html += '</div>';
        }

        // Documents
        if (v.documents && v.documents.length > 0) {
            html += '<div class="rvd-section">';
            html += '<div class="rvd-section-title"><i class="bi bi-paperclip"></i> Supporting Documents <span style="font-weight:500;color:var(--text-secondary);font-size:0.75rem;text-transform:none;letter-spacing:0;margin-left:0.35rem;">(' + v.documents.length + ')</span></div>';
            html += '<div class="rvd-doc-list">';
            v.documents.forEach(function(doc) {
                var fname = doc.original_filename || 'Document';
                var ext = fname.split('.').pop().toLowerCase();
                var isImg = ['jpg','jpeg','png','gif','webp'].indexOf(ext) !== -1;
                var isPdf = ext === 'pdf';
                var isWord = ['doc','docx'].indexOf(ext) !== -1;
                var iconClass = isPdf ? 'bi-file-pdf' : isImg ? 'bi-file-image' : isWord ? 'bi-file-word' : 'bi-file-earmark';
                var wrapClass = isPdf ? 'rvd-pdf' : isImg ? 'rvd-img' : isWord ? 'rvd-word' : 'rvd-file';
                var docId = doc.document_id || doc.id;
                html += '<div class="rvd-doc-item">';
                html += '<div class="rvd-doc-icon-wrap ' + wrapClass + '"><i class="bi ' + iconClass + '"></i></div>';
                html += '<div class="rvd-doc-info"><div class="rvd-doc-name" title="' + rvdEsc(fname) + '">' + rvdEsc(fname) + '</div>';
                html += '<div class="rvd-doc-meta">' + rvdFormatSize(doc.file_size) + '</div></div>';
                html += '<div class="rvd-doc-actions"><a href="download_document.php?type=rental_verification&id=' + docId + '" class="rvd-btn-doc rvd-download" title="Download"><i class="bi bi-download"></i></a></div>';
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';
        }

        // Timeline
        html += '<div class="rvd-section">';
        html += '<div class="rvd-section-title"><i class="bi bi-clock-history"></i> Timeline</div>';
        html += '<div class="rvd-timeline">';
        html += '<div class="rvd-tl-item"><div class="rvd-tl-dot gold"><i class="bi bi-upload"></i></div><div class="rvd-tl-content"><div class="rvd-tl-event">Verification Submitted</div><div class="rvd-tl-time">' + (v.submitted_at_fmt || '\u2014') + '</div></div></div>';
        if (v.reviewed_at) {
            var tlDotCls = stClass === 'approved' ? 'green' : 'red';
            var tlIcon   = stClass === 'approved' ? 'bi-check-lg' : 'bi-x-lg';
            var reviewer = '';
            if (v.reviewer_first) { reviewer = ' by ' + rvdEsc(v.reviewer_first) + ' ' + rvdEsc(v.reviewer_last); }
            html += '<div class="rvd-tl-item"><div class="rvd-tl-dot ' + tlDotCls + '"><i class="bi ' + tlIcon + '"></i></div><div class="rvd-tl-content"><div class="rvd-tl-event">Verification ' + v.status + reviewer + '</div><div class="rvd-tl-time">' + (v.reviewed_at_fmt || '') + '</div></div></div>';
        } else {
            html += '<div class="rvd-tl-item"><div class="rvd-tl-dot gray"><i class="bi bi-hourglass-split"></i></div><div class="rvd-tl-content"><div class="rvd-tl-event">Awaiting Review</div><div class="rvd-tl-time">Pending admin decision</div></div></div>';
        }
        html += '</div></div>';

        html += '</div>'; // close rvd-body

        document.getElementById('modalContent').innerHTML = html;

        // Init hero gallery
        rvdGalleryInit(totalImgs);

        // Footer buttons
        var footer = '';
        if (v.status === 'Pending') {
            footer = '<button class="btn-modal btn-modal-success" onclick="openApproveModal(' + v.verification_id + ',' + v.property_id + ',' + v.agent_id + ')"><i class="bi bi-check-lg"></i> Approve</button>' +
                     '<button class="btn-modal btn-modal-danger" onclick="openRejectModal(' + v.verification_id + ')"><i class="bi bi-x-lg"></i> Reject</button>' +
                     '<button class="btn-modal btn-modal-secondary" onclick="closeModal(\'detailsModal\')"><i class="bi bi-x-lg"></i> Close</button>';
        } else {
            footer = '<button class="btn-modal btn-modal-secondary" onclick="closeModal(\'detailsModal\')"><i class="bi bi-x-lg"></i> Close</button>';
        }
        document.getElementById('modalFooter').innerHTML = footer;

        openModal('detailsModal');
    }

    /* ===== RVD HERO GALLERY ===== */
    var rvdGalIdx = 0, rvdGalTotal = 0;
    function rvdGalleryInit(total) { rvdGalIdx = 0; rvdGalTotal = total; rvdUpdateGallery(); }
    function rvdUpdateGallery() {
        for (var i = 0; i < rvdGalTotal; i++) {
            var img = document.getElementById('rvd-img-' + i);
            var dot = document.getElementById('rvd-dot-' + i);
            if (img) img.style.opacity = i === rvdGalIdx ? '1' : '0';
            if (dot) { if (i === rvdGalIdx) dot.classList.add('active'); else dot.classList.remove('active'); }
        }
        var prev = document.getElementById('rvdPrev');
        var next = document.getElementById('rvdNext');
        var counter = document.getElementById('rvdCounter');
        if (prev) prev.disabled = rvdGalIdx === 0;
        if (next) next.disabled = rvdGalIdx >= rvdGalTotal - 1;
        if (counter) counter.textContent = (rvdGalIdx + 1) + ' / ' + rvdGalTotal;
    }
    function rvdGoNext() { if (rvdGalIdx < rvdGalTotal - 1) { rvdGalIdx++; rvdUpdateGallery(); } }
    function rvdGoPrev() { if (rvdGalIdx > 0) { rvdGalIdx--; rvdUpdateGallery(); } }
    function rvdGoTo(i) { rvdGalIdx = i; rvdUpdateGallery(); }

    /* ===== APPROVE MODAL ===== */
    var currentApproveVid = null;
    var currentMonthlyRent = 0;

    function openApproveModal(vid, pid, aid) {
        currentApproveVid = vid;
        document.getElementById('approve_verification_id').value = vid;
        document.getElementById('approve_property_id').value = pid;
        document.getElementById('approve_agent_id').value = aid;
        document.getElementById('approve_commission_rate').value = '';
        document.getElementById('commPreviewVal').textContent = '\u2014';
        document.getElementById('commPreviewVal').classList.add('fsm-dim');

        // Get monthly rent from card data
        var cards = document.querySelectorAll('.rental-card');
        for (var i = 0; i < cards.length; i++) {
            try {
                var d = JSON.parse(cards[i].getAttribute('data-verification'));
                if (parseInt(d.verification_id) === vid) {
                    currentMonthlyRent = parseFloat(d.monthly_rent) || 0;
                    break;
                }
            } catch(e) {}
        }

        new bootstrap.Modal(document.getElementById('approveModal')).show();
    }

    // Live commission preview
    document.getElementById('approve_commission_rate').addEventListener('input', function() {
        var rate = parseFloat(this.value) || 0;
        var el = document.getElementById('commPreviewVal');
        if (rate > 0 && currentMonthlyRent > 0) {
            var comm = (currentMonthlyRent * rate / 100);
            el.textContent = '\u20B1' + comm.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            el.classList.remove('fsm-dim');
        } else {
            el.textContent = '\u2014';
            el.classList.add('fsm-dim');
        }
    });

    // Approve form submit
    document.getElementById('approveForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.getElementById('approveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:pc-spin 1s linear infinite;"></i> Processing...';

        showProcessingOverlay('Approving Rental', 'Setting up lease and commission...');

        fetch('admin_finalize_rental.php', { method: 'POST', body: new FormData(this) })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                hideProcessingOverlay();
                if (data.ok) {
                    bootstrap.Modal.getInstance(document.getElementById('approveModal')).hide();
                    showToast('success', 'Rental Approved', data.message, 5000);
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
                btn.innerHTML = '<i class="bi bi-check2-circle"></i> Approve & Finalize';
            });
    });

    /* ===== REJECT MODAL ===== */
    var currentRejectVid = null;

    function openRejectModal(vid) {
        currentRejectVid = vid;
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
        formData.append('verification_id', currentRejectVid);
        formData.append('admin_notes', reason);

        fetch('admin_reject_rental.php', { method: 'POST', body: formData })
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
    var allCards = document.querySelectorAll('.rental-card');

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

    // Quick rent range buttons
    document.querySelectorAll('[data-rent-range]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var active = this.classList.contains('active');
            document.querySelectorAll('[data-rent-range]').forEach(function(b) { b.classList.remove('active'); });
            if (!active) {
                this.classList.add('active');
                var parts = this.getAttribute('data-rent-range').split('-');
                document.getElementById('sfRentMin').value = parts[0];
                document.getElementById('sfRentMax').value = parts[1] < 999999999 ? parts[1] : '';
            } else {
                document.getElementById('sfRentMin').value = '';
                document.getElementById('sfRentMax').value = '';
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
    ['sfRentMin','sfRentMax','sfDateFrom','sfDateTo','sfAgentSelect','sfCitySelect','sfSortSelect'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', sfPreview);
        if (el) el.addEventListener('change', sfPreview);
    });

    function getFilters() {
        var sf = {};
        sf.search = (document.getElementById('sfSearchInput').value || document.getElementById('quickSearchInput').value || '').toLowerCase().trim();
        sf.rentMin = parseFloat(document.getElementById('sfRentMin').value) || 0;
        sf.rentMax = parseFloat(document.getElementById('sfRentMax').value) || Infinity;
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
        if (document.getElementById('sfRentMin').value || document.getElementById('sfRentMax').value) n++;
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
            var searchable = [d.StreetAddress||'', d.City||'', d.Province||'', d.agent_first||'', d.agent_last||'', d.tenant_name||'', d.tenant_email||''].join(' ').toLowerCase();
            if (searchable.indexOf(sf.search) === -1) return false;
        }
        // Rent range
        var rent = parseFloat(d.monthly_rent) || 0;
        if (rent < sf.rentMin || rent > sf.rentMax) return false;
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
                var da = JSON.parse(a.getAttribute('data-verification'));
                var db = JSON.parse(b.getAttribute('data-verification'));
                switch (sortVal) {
                    case 'oldest':    return new Date(da.submitted_at) - new Date(db.submitted_at);
                    case 'newest':    return new Date(db.submitted_at) - new Date(da.submitted_at);
                    case 'rent_high': return parseFloat(db.monthly_rent) - parseFloat(da.monthly_rent);
                    case 'rent_low':  return parseFloat(da.monthly_rent) - parseFloat(db.monthly_rent);
                    case 'agent_az':  return (da.agent_first + ' ' + da.agent_last).localeCompare(db.agent_first + ' ' + db.agent_last);
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
                var d = JSON.parse(card.getAttribute('data-verification'));
                if (matchesFilters(d, sf)) count++;
            } catch(e) { count++; }
        });
        document.getElementById('sfResultsNum').textContent = count;
        updateBadge();
    }

    function sfApply() {
        var sf = getFilters();
        var grid = document.querySelector('.rentals-grid');
        var visible = [];
        allCards.forEach(function(card) {
            try {
                var d = JSON.parse(card.getAttribute('data-verification'));
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
        document.getElementById('sfRentMin').value = '';
        document.getElementById('sfRentMax').value = '';
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
