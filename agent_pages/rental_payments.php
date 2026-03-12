<?php
session_start();
require_once '../connection.php';
require_once __DIR__ . '/../config/session_timeout.php';
require_once __DIR__ . '/../config/paths.php';

if (!isset($_SESSION['account_id']) || !in_array($_SESSION['user_role'], ['agent', 'admin'])) {
    header("Location: ../login.php");
    exit();
}

$agent_id = (int) $_SESSION['account_id'];
$is_admin_user = ($_SESSION['user_role'] === 'admin');
$back_url = $is_admin_user ? '../view_property.php' : 'agent_property.php';
$fallback_url = $is_admin_user ? '../property.php' : 'agent_property.php';

// Get rental_id from query param (or property_id)
$rental_id = isset($_GET['rental_id']) ? (int)$_GET['rental_id'] : 0;
$property_id_param = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;

// If property_id given, find rental_id
if ($rental_id <= 0 && $property_id_param > 0) {
    $find = $conn->prepare("SELECT rental_id FROM finalized_rentals WHERE property_id = ? AND agent_id = ? AND lease_status IN ('Active','Renewed') ORDER BY finalized_at DESC LIMIT 1");
    $find->bind_param("ii", $property_id_param, $agent_id);
    $find->execute();
    $fr = $find->get_result()->fetch_assoc();
    if ($fr) $rental_id = (int)$fr['rental_id'];
    $find->close();
}

if ($rental_id <= 0) {
    header("Location: $fallback_url");
    exit();
}

// Fetch lease details
$lease_stmt = $conn->prepare("
    SELECT fr.*, p.StreetAddress, p.City, p.Barangay, p.Province, p.PropertyType,
           (SELECT PhotoURL FROM property_images WHERE property_ID = p.property_ID AND SortOrder = 1 LIMIT 1) AS thumb
    FROM finalized_rentals fr
    JOIN property p ON fr.property_id = p.property_ID
    WHERE fr.rental_id = ? AND fr.agent_id = ?
");
$lease_stmt->bind_param("ii", $rental_id, $agent_id);
$lease_stmt->execute();
$lease = $lease_stmt->get_result()->fetch_assoc();
$lease_stmt->close();

if (!$lease) {
    header("Location: $fallback_url");
    exit();
}

// Fetch payment history
$pay_stmt = $conn->prepare("
    SELECT rp.*,
           rc.commission_amount, rc.commission_percentage, rc.status AS commission_status
    FROM rental_payments rp
    LEFT JOIN rental_commissions rc ON rp.payment_id = rc.payment_id
    WHERE rp.rental_id = ?
    ORDER BY rp.period_start DESC
");
$pay_stmt->bind_param("i", $rental_id);
$pay_stmt->execute();
$payments = $pay_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pay_stmt->close();

// Calculate next suggested period
$last_pay = $conn->prepare("
    SELECT period_end FROM rental_payments 
    WHERE rental_id = ? AND status IN ('Pending','Confirmed')
    ORDER BY period_end DESC LIMIT 1
");
$last_pay->bind_param("i", $rental_id);
$last_pay->execute();
$lp = $last_pay->get_result()->fetch_assoc();
$last_pay->close();

if ($lp) {
    $next_start = date('Y-m-d', strtotime($lp['period_end'] . ' + 1 day'));
} else {
    $next_start = $lease['lease_start_date'];
}
$next_end = date('Y-m-d', strtotime($next_start . ' + 1 month - 1 day'));

$confirmed_count = count(array_filter($payments, fn($p) => $p['status'] === 'Confirmed'));
$pending_count = count(array_filter($payments, fn($p) => $p['status'] === 'Pending'));
$total_commission = array_sum(array_column(array_filter($payments, fn($p) => in_array($p['commission_status'], ['calculated', 'paid'])), 'commission_amount'));
$is_active = in_array($lease['lease_status'], ['Active', 'Renewed']);

// Capture session message for toast
$sk_toast_msg   = '';
$sk_toast_type  = 'info';
$sk_toast_title = 'Notice';
if (isset($_SESSION['message'])) {
    $sk_toast_msg = addslashes(htmlspecialchars($_SESSION['message']));
    if ($_SESSION['message_type'] === 'success')    { $sk_toast_type = 'success'; $sk_toast_title = 'Success'; }
    elseif ($_SESSION['message_type'] === 'danger') { $sk_toast_type = 'error';   $sk_toast_title = 'Error'; }
    unset($_SESSION['message'], $_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/Logo.png" type="image/png">
    <link rel="shortcut icon" href="../images/Logo.png" type="image/png">
    <title>Lease Management - <?= htmlspecialchars($lease['StreetAddress']) ?></title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    <style>
        /* ===== CSS VARIABLES — Agent Portal Dark Theme ===== */
        :root {
            --gold: #d4af37; --gold-light: #f4d03f; --gold-dark: #b8941f;
            --blue: #2563eb; --blue-light: #3b82f6; --blue-dark: #1e40af;
            --black: #0a0a0a; --black-light: #111111; --black-lighter: #1a1a1a;
            --white: #ffffff;
            --gray-300: #d1d5db; --gray-400: #9ca4ab; --gray-500: #7a8a99;
            --gray-600: #5d6d7d; --gray-900: #1a1f24;
            --card-bg: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
            --card-border: rgba(37,99,235,0.15);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--black); color: var(--white); line-height: 1.6; }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--black); }
        ::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.3); border-radius: 3px; }

        /* ===== CONTENT WRAP ===== */
        .lease-content { max-width: 1400px; margin: 0 auto; padding: 2rem 2rem 4rem; }

        /* ===== PAGE HEADER ===== */
        .page-header { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1.25rem; }
        .page-header .back-link { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.8rem; color: var(--gray-400); text-decoration: none; margin-bottom: 0.5rem; transition: color 0.2s; }
        .page-header .back-link:hover { color: var(--gold); }
        .page-header h1 { font-size: 1.55rem; font-weight: 800; color: var(--white); margin: 0 0 0.2rem; }
        .page-header .address-sub { font-size: 0.88rem; color: var(--gray-400); display: flex; align-items: center; gap: 0.35rem; }
        .page-header .address-sub i { color: var(--blue-light); font-size: 0.78rem; }

        /* Header action buttons */
        .header-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-start; padding-top: 0.25rem; }
        .btn-record  { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.55rem 1rem; border: none; border-radius: 4px; font-size: 0.82rem; font-weight: 700; cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em; transition: all 0.3s; }
        .btn-record  { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #0a0a0a; }
        .btn-record:hover  { box-shadow: 0 4px 16px rgba(212,175,55,0.35); transform: translateY(-1px); }
        .btn-renew   { background: linear-gradient(135deg, #15803d, #16a34a); color: #fff; }
        .btn-renew:hover   { box-shadow: 0 4px 16px rgba(22,163,74,0.3); transform: translateY(-1px); }
        .btn-terminate { background: linear-gradient(135deg, #b91c1c, #dc2626); color: #fff; }
        .btn-terminate:hover { box-shadow: 0 4px 16px rgba(220,38,38,0.3); transform: translateY(-1px); }

        /* Lease status badge */
        .lease-status-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.22rem 0.65rem; border-radius: 3px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .lease-status-badge.active     { background: rgba(34,197,94,0.12);  color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }
        .lease-status-badge.renewed    { background: rgba(37,99,235,0.12);  color: #60a5fa; border: 1px solid rgba(37,99,235,0.2); }
        .lease-status-badge.terminated { background: rgba(239,68,68,0.12);  color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
        .lease-status-badge.expired    { background: rgba(245,158,11,0.12); color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }

        /* ===== KPI GRID ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 0.85rem; transition: all 0.2s; }
        .kpi-card:hover { transform: translateY(-2px); border-color: rgba(37,99,235,0.3); box-shadow: 0 4px 16px rgba(37,99,235,0.08); }
        .kpi-icon { width: 44px; height: 44px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .kpi-icon.green  { background: linear-gradient(135deg, rgba(34,197,94,0.1), rgba(34,197,94,0.2)); color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }
        .kpi-icon.amber  { background: linear-gradient(135deg, rgba(245,158,11,0.1), rgba(245,158,11,0.2)); color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }
        .kpi-icon.blue   { background: linear-gradient(135deg, rgba(37,99,235,0.1), rgba(37,99,235,0.2)); color: #3b82f6; border: 1px solid rgba(37,99,235,0.2); }
        .kpi-icon.gold   { background: linear-gradient(135deg, rgba(212,175,55,0.1), rgba(212,175,55,0.2)); color: var(--gold); border: 1px solid rgba(212,175,55,0.2); }
        .kpi-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: var(--gray-400); margin-bottom: 0.1rem; }
        .kpi-value { font-size: 1.35rem; font-weight: 800; color: var(--white); }

        /* ===== LEASE SUMMARY CARD ===== */
        .section-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .section-card::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .section-card-header { padding: 1rem 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.04); display: flex; align-items: center; justify-content: space-between; }
        .section-card-header h6 { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--gray-400); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .section-card-header h6 i { color: var(--gold); }
        .section-card-body { padding: 1.5rem; }

        .lease-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); gap: 1.25rem; }
        .lease-info-item .label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-500); margin-bottom: 0.25rem; }
        .lease-info-item .value { font-size: 1rem; font-weight: 700; color: var(--white); }
        .lease-info-item .value.gold-val { color: var(--gold); }
        .lease-info-item .value.green-val { color: #22c55e; }
        .lease-info-item .value.blue-val  { color: #60a5fa; }

        /* ===== PAYMENT TABLE ===== */
        .table-responsive { overflow-x: auto; }
        .payment-table { width: 100%; margin: 0; border-collapse: collapse; }
        .payment-table thead tr { background: rgba(255,255,255,0.03); border-bottom: 1px solid rgba(255,255,255,0.06); }
        .payment-table th { padding: 0.75rem 1.25rem; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--gray-400); white-space: nowrap; }
        .payment-table tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
        .payment-table tbody tr:last-child { border-bottom: none; }
        .payment-table tbody tr:hover { background: rgba(37,99,235,0.04); }
        .payment-table td { padding: 0.85rem 1.25rem; font-size: 0.875rem; color: var(--white); vertical-align: middle; }
        .payment-table td.muted { color: var(--gray-400); }

        /* Payment status badges */
        .pay-badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.18rem 0.55rem; border-radius: 2px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .pay-badge.pending   { background: rgba(245,158,11,0.12); color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }
        .pay-badge.confirmed { background: rgba(34,197,94,0.12);  color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }
        .pay-badge.rejected  { background: rgba(239,68,68,0.12);  color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 4rem 2rem; }
        .empty-state i { font-size: 2.75rem; color: var(--gray-500); opacity: 0.3; margin-bottom: 0.75rem; display: block; }
        .empty-state h5 { font-size: 1rem; font-weight: 700; color: var(--white); margin-bottom: 0.25rem; }
        .empty-state p { color: var(--gray-400); margin: 0; font-size: 0.875rem; }

        /* ===== MODAL DARK THEME ===== */
        .modal-dark .modal-content {
            background: linear-gradient(180deg, #141414 0%, #0f0f0f 100%);
            border: 1px solid rgba(37,99,235,0.2);
            box-shadow: 0 20px 60px rgba(0,0,0,0.8);
            color: var(--white);
            border-radius: 6px;
        }
        .modal-dark .modal-header {
            background: linear-gradient(180deg, #141414 0%, #111111 100%);
            border-bottom: 1px solid rgba(212,175,55,0.2);
            padding: 1.25rem 1.5rem;
        }
        .modal-dark .modal-title { font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .modal-dark .modal-title i { color: var(--gold); }
        .modal-dark .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .modal-dark .modal-body { padding: 1.5rem; overflow-y: auto; max-height: calc(100vh - 210px); }
        .modal-dark .modal-body::-webkit-scrollbar { width: 6px; }
        .modal-dark .modal-body::-webkit-scrollbar-track { background: rgba(26,26,26,0.4); }
        .modal-dark .modal-body::-webkit-scrollbar-thumb { background: linear-gradient(180deg, var(--gold), var(--gold-dark)); border-radius: 3px; }
        .modal-dark .modal-footer { border-top: 1px solid rgba(37,99,235,0.1); padding: 1rem 1.5rem; }
        .modal-dark .form-label { font-weight: 600; font-size: 0.85rem; color: var(--gray-300); }
        .modal-dark .form-label .req { color: #ef4444; }
        .modal-dark .form-control,
        .modal-dark .form-select {
            background: rgba(26,26,26,0.8); border: 1px solid rgba(37,99,235,0.15);
            color: var(--white); border-radius: 4px; padding: 0.6rem 0.8rem; font-size: 0.9rem; transition: all 0.3s;
        }
        .modal-dark .form-control:focus,
        .modal-dark .form-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.15); background: rgba(26,26,26,0.95); outline: none; }
        .modal-dark .form-control:focus { color: var(--white); }
        .modal-dark .form-control::placeholder { color: var(--gray-600); }
        .modal-dark .form-text { color: var(--gray-500); font-size: 0.78rem; }
        .modal-dark .form-control[type="date"] { color-scheme: dark; }
        .modal-dark .form-control[type="file"] { background: rgba(255,255,255,0.03); }
        .modal-dark .form-control[type="file"]::-webkit-file-upload-button {
            background: rgba(37,99,235,0.12); border: 1px solid rgba(37,99,235,0.2);
            color: var(--blue-light); padding: 0.3rem 0.75rem; border-radius: 3px;
            font-size: 0.78rem; font-weight: 600; cursor: pointer; margin-right: 0.75rem; transition: all 0.2s;
        }
        .modal-dark .input-group-text {
            background: rgba(37,99,235,0.08); border: 1px solid rgba(37,99,235,0.15);
            color: var(--gold); font-weight: 700;
        }
        /* Modal info banner */
        .modal-info-banner { background: rgba(37,99,235,0.08); border: 1px solid rgba(37,99,235,0.2); border-radius: 4px; padding: 0.6rem 0.9rem; font-size: 0.82rem; color: var(--white); display: flex; align-items: center; gap: 0.5rem; }
        .modal-info-banner i { color: var(--blue-light); flex-shrink: 0; }
        /* Modal warning banner */
        .modal-warning-banner { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); border-radius: 4px; padding: 0.75rem 1rem; font-size: 0.84rem; color: var(--white); }
        .modal-warning-banner .warning-title { font-weight: 700; color: #ef4444; display: flex; align-items: center; gap: 0.4rem; margin-bottom: 0.4rem; }
        .modal-warning-banner ul { margin: 0; padding-left: 1.25rem; }
        .modal-warning-banner li { margin-bottom: 0.2rem; }
        /* Modal cancel button */
        .btn-modal-cancel { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--gray-300); padding: 0.55rem 1rem; border-radius: 4px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-modal-cancel:hover { background: rgba(255,255,255,0.1); color: var(--white); }
        /* Modal submit buttons */
        .btn-modal-submit-gold { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #0a0a0a; border: none; padding: 0.55rem 1.1rem; border-radius: 4px; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-modal-submit-gold:hover { box-shadow: 0 4px 16px rgba(212,175,55,0.35); transform: translateY(-1px); }
        .btn-modal-submit-gold:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-modal-submit-green { background: linear-gradient(135deg, #15803d, #16a34a); color: #fff; border: none; padding: 0.55rem 1.1rem; border-radius: 4px; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-modal-submit-green:hover { box-shadow: 0 4px 16px rgba(22,163,74,0.3); transform: translateY(-1px); }
        .btn-modal-submit-green:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-modal-submit-red { background: linear-gradient(135deg, #b91c1c, #dc2626); color: #fff; border: none; padding: 0.55rem 1.1rem; border-radius: 4px; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-modal-submit-red:hover { box-shadow: 0 4px 16px rgba(220,38,38,0.3); transform: translateY(-1px); }
        .btn-modal-submit-red:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ===== TOAST — Agent Portal (Dark Theme) ===== */
        #toastContainer { position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.6rem; pointer-events: none; }
        .app-toast { display: flex; align-items: flex-start; gap: 0.85rem; background: linear-gradient(135deg, rgba(26,26,26,0.97) 0%, rgba(10,10,10,0.98) 100%); border: 1px solid rgba(37,99,235,0.15); border-radius: 12px; padding: 0.9rem 1.1rem; min-width: 300px; max-width: 400px; box-shadow: 0 8px 32px rgba(0,0,0,0.5); pointer-events: all; position: relative; overflow: hidden; animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards; backdrop-filter: blur(12px); }
        @keyframes toast-in  { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }
        .app-toast::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.15); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.12);  color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.12);  color: #3b82f6; }
        .app-toast-body      { flex: 1; min-width: 0; }
        .app-toast-title     { font-size: 0.82rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.2rem; }
        .app-toast-msg       { font-size: 0.78rem; color: #9ca4ab; line-height: 1.4; word-break: break-word; }
        .app-toast-close { background: none; border: none; cursor: pointer; color: #5d6d7d; font-size: 0.8rem; padding: 0; line-height: 1; flex-shrink: 0; transition: color .2s; }
        .app-toast-close:hover { color: #f1f5f9; }
        .app-toast-progress { position: absolute; bottom: 0; left: 0; height: 2px; border-radius: 0 0 0 12px; }
        .toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* ===== SKELETON SCREEN SYSTEM ===== */
        @keyframes sk-shimmer { 0% { background-position: -1600px 0; } 100% { background-position: 1600px 0; } }
        .sk-shimmer { background: linear-gradient(90deg, #1a1a1a 25%, #222 50%, #1a1a1a 75%); background-size: 1600px 100%; animation: sk-shimmer 1.6s ease-in-out infinite; border-radius: 4px; }
        #page-content { display: none; }

        /* ===== SPIN ANIMATION ===== */
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .spin { display: inline-block; animation: spin .7s linear infinite; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .lease-content { padding: 1rem; }
            .page-header { padding: 1.25rem; }
            .page-header h1 { font-size: 1.2rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .lease-info-grid { grid-template-columns: repeat(2, 1fr); }
            .header-actions { width: 100%; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<?php if ($is_admin_user): ?>
    <?php include __DIR__ . '/../admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../admin_navbar.php'; ?>
<?php else: ?>
    <?php
    $active_page = 'agent_rental_payments.php';
    include __DIR__ . '/agent_navbar.php';
    ?>
<?php endif; ?>

<noscript><style>
    #sk-screen    { display: none !important; }
    #page-content { display: block !important; opacity: 1 !important; }
</style></noscript>

<!-- ============================
     SKELETON SCREEN
     ============================ -->
<div id="sk-screen" role="presentation" aria-hidden="true">
    <div class="lease-content">
        <!-- Skeleton: Page Header -->
        <div style="background:rgba(26,26,26,0.8);border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:2rem 2.5rem;margin-bottom:1.5rem;">
            <div class="sk-shimmer" style="width:120px;height:12px;margin-bottom:10px;"></div>
            <div class="sk-shimmer" style="width:290px;height:22px;margin-bottom:8px;"></div>
            <div class="sk-shimmer" style="width:180px;height:13px;"></div>
        </div>
        <!-- Skeleton: KPI Grid -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;">
            <?php for ($i = 0; $i < 4; $i++): ?>
            <div style="background:rgba(26,26,26,0.8);border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:1.1rem;display:flex;align-items:center;gap:0.85rem;">
                <div class="sk-shimmer" style="width:44px;height:44px;border-radius:4px;flex-shrink:0;"></div>
                <div style="flex:1;"><div class="sk-shimmer" style="width:70%;height:10px;margin-bottom:6px;"></div><div class="sk-shimmer" style="width:45%;height:18px;"></div></div>
            </div>
            <?php endfor; ?>
        </div>
        <!-- Skeleton: Lease Summary Card -->
        <div style="background:rgba(26,26,26,0.8);border:1px solid rgba(37,99,235,0.15);border-radius:4px;margin-bottom:1.5rem;">
            <div style="padding:1rem 1.5rem;border-bottom:1px solid rgba(255,255,255,0.04);">
                <div class="sk-shimmer" style="width:140px;height:12px;"></div>
            </div>
            <div style="padding:1.5rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:1.25rem;">
                <?php for ($i = 0; $i < 7; $i++): ?>
                <div><div class="sk-shimmer" style="width:70px;height:10px;margin-bottom:8px;"></div><div class="sk-shimmer" style="width:110px;height:16px;"></div></div>
                <?php endfor; ?>
            </div>
        </div>
        <!-- Skeleton: Table -->
        <div style="background:rgba(26,26,26,0.8);border:1px solid rgba(37,99,235,0.15);border-radius:4px;overflow:hidden;">
            <div style="padding:1rem 1.5rem;border-bottom:1px solid rgba(255,255,255,0.04);">
                <div class="sk-shimmer" style="width:160px;height:14px;"></div>
            </div>
            <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.85rem;">
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="display:flex;gap:1rem;align-items:center;">
                    <div class="sk-shimmer" style="width:30px;height:13px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:90px;height:13px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:130px;height:13px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:80px;height:13px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:65px;height:20px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:80px;height:13px;border-radius:3px;"></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================
     REAL PAGE CONTENT
     ============================ -->
<div id="page-content">
<div class="lease-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-inner">
            <div>
                <a href="<?= $is_admin_user ? '../view_property.php?id=' . (int)$lease['property_id'] : 'agent_rental_payments.php' ?>" class="back-link">
                    <i class="bi bi-arrow-left"></i> Back to Rental Payments
                </a>
                <h1><i class="bi bi-house-door me-2" style="color:var(--gold);"></i><?= htmlspecialchars($lease['StreetAddress']) ?></h1>
                <div class="address-sub"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($lease['City'] . ', ' . $lease['Province']) ?></div>
            </div>
            <div class="header-actions">
                <?php if ($is_active): ?>
                    <button class="btn-record" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                        <i class="bi bi-plus-circle"></i> Record Payment
                    </button>
                    <button class="btn-record btn-renew" data-bs-toggle="modal" data-bs-target="#renewLeaseModal">
                        <i class="bi bi-arrow-repeat"></i> Renew Lease
                    </button>
                    <button class="btn-record btn-terminate" data-bs-toggle="modal" data-bs-target="#terminateLeaseModal">
                        <i class="bi bi-x-circle"></i> Terminate Lease
                    </button>
                <?php elseif ($lease['lease_status'] === 'Expired'): ?>
                    <button class="btn-record btn-renew" data-bs-toggle="modal" data-bs-target="#renewLeaseModal">
                        <i class="bi bi-arrow-repeat"></i> Renew Lease
                    </button>
                    <button class="btn-record btn-terminate" data-bs-toggle="modal" data-bs-target="#terminateLeaseModal">
                        <i class="bi bi-x-circle"></i> End Lease
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div><div class="kpi-label">Confirmed</div><div class="kpi-value"><?= $confirmed_count ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon amber"><i class="bi bi-clock-fill"></i></div>
            <div><div class="kpi-label">Pending Review</div><div class="kpi-value"><?= $pending_count ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon blue"><i class="bi bi-coin"></i></div>
            <div><div class="kpi-label">Commission Earned</div><div class="kpi-value">&#8369;<?= number_format($total_commission, 0) ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon gold"><i class="bi bi-receipt"></i></div>
            <div><div class="kpi-label">Total Payments</div><div class="kpi-value"><?= count($payments) ?></div></div>
        </div>
    </div>

    <!-- Lease Summary -->
    <div class="section-card">
        <div class="section-card-header">
            <h6><i class="bi bi-file-text-fill"></i> Lease Summary</h6>
            <?php
            $statusClass = match($lease['lease_status']) {
                'Active'     => 'active',
                'Renewed'    => 'renewed',
                'Terminated' => 'terminated',
                'Expired'    => 'expired',
                default      => 'active'
            };
            ?>
            <span class="lease-status-badge <?= $statusClass ?>">
                <i class="bi bi-circle-fill" style="font-size:0.35rem;"></i>
                <?= htmlspecialchars($lease['lease_status']) ?>
            </span>
        </div>
        <div class="section-card-body">
            <div class="lease-info-grid">
                <div class="lease-info-item">
                    <div class="label">Tenant</div>
                    <div class="value"><?= htmlspecialchars($lease['tenant_name']) ?></div>
                </div>
                <div class="lease-info-item">
                    <div class="label">Monthly Rent</div>
                    <div class="value gold-val">&#8369;<?= number_format($lease['monthly_rent'], 2) ?></div>
                </div>
                <div class="lease-info-item">
                    <div class="label">Security Deposit</div>
                    <div class="value">&#8369;<?= number_format($lease['security_deposit'], 2) ?></div>
                </div>
                <div class="lease-info-item">
                    <div class="label">Lease Start</div>
                    <div class="value"><?= date('M d, Y', strtotime($lease['lease_start_date'])) ?></div>
                </div>
                <div class="lease-info-item">
                    <div class="label">Lease End</div>
                    <div class="value"><?= date('M d, Y', strtotime($lease['lease_end_date'])) ?></div>
                </div>
                <div class="lease-info-item">
                    <div class="label">Term</div>
                    <div class="value blue-val"><?= (int)$lease['lease_term_months'] ?> months</div>
                </div>
                <div class="lease-info-item">
                    <div class="label">Commission Rate</div>
                    <div class="value green-val"><?= $lease['commission_rate'] ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment History Table -->
    <div class="section-card">
        <div class="section-card-header">
            <h6><i class="bi bi-clock-history"></i> Payment History</h6>
        </div>
        <?php if (empty($payments)): ?>
            <div class="empty-state">
                <i class="bi bi-cash-stack"></i>
                <h5>No Payments Recorded Yet</h5>
                <p>Use the "Record Payment" button above to log the first rent payment for this lease.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="payment-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Payment Date</th>
                        <th>Period Covered</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Commission</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = count($payments); foreach ($payments as $p): ?>
                    <tr>
                        <td class="muted"><?= $idx-- ?></td>
                        <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                        <td class="muted">
                            <?= date('M d', strtotime($p['period_start'])) ?>
                            &ndash;
                            <?= date('M d, Y', strtotime($p['period_end'])) ?>
                        </td>
                        <td style="font-weight:700;color:var(--gold);">&#8369;<?= number_format($p['payment_amount'], 2) ?></td>
                        <td>
                            <?php $bc = strtolower($p['status']); ?>
                            <span class="pay-badge <?= $bc ?>">
                                <i class="bi bi-circle-fill" style="font-size:0.3rem;"></i>
                                <?= htmlspecialchars($p['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($p['commission_amount'])): ?>
                                <span style="color:#22c55e;font-weight:600;">&#8369;<?= number_format($p['commission_amount'], 2) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= date('M d, Y', strtotime($p['submitted_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>
</div><!-- /#page-content -->

<!-- ============================
     MODALS (Dark Theme)
     ============================ -->

<!-- Record Payment Modal -->
<style>
/* ===== RECORD PAYMENT MODAL EXTRAS ===== */
.rp-modal-grid { display: grid; grid-template-columns: 1fr 320px; gap: 0; }
@media (max-width: 768px) { .rp-modal-grid { grid-template-columns: 1fr; } }

/* Left form panel */
.rp-form-panel { padding: 1.5rem; border-right: 1px solid rgba(255,255,255,0.05); overflow-y: auto; max-height: 68vh; }
.rp-form-panel::-webkit-scrollbar { width: 5px; }
.rp-form-panel::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.25); border-radius: 3px; }

/* Section label inside form */
.rp-section-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--gray-500); margin: 0 0 0.7rem; padding-bottom: 0.35rem; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; gap: 0.4rem; }
.rp-section-label i { color: var(--gold); }

/* Quick amount chips */
.quick-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem; }
.quick-chip { padding: 0.2rem 0.6rem; border-radius: 3px; font-size: 0.72rem; font-weight: 600; cursor: pointer; border: 1px solid rgba(212,175,55,0.25); background: rgba(212,175,55,0.07); color: var(--gold); transition: all 0.18s; white-space: nowrap; }
.quick-chip:hover { background: rgba(212,175,55,0.18); border-color: rgba(212,175,55,0.5); }
.quick-chip.active { background: rgba(212,175,55,0.22); border-color: var(--gold); }

/* Period row + auto-fill badge */
.period-auto-badge { font-size: 0.65rem; font-weight: 600; padding: 0.1rem 0.4rem; border-radius: 2px; background: rgba(37,99,235,0.12); color: var(--blue-light); border: 1px solid rgba(37,99,235,0.2); vertical-align: middle; margin-left: 0.35rem; }

/* File drop zone */
.rp-drop-zone { border: 1.5px dashed rgba(37,99,235,0.3); border-radius: 5px; padding: 1rem 1.25rem; text-align: center; cursor: pointer; transition: all 0.22s; background: rgba(37,99,235,0.04); }
.rp-drop-zone:hover, .rp-drop-zone.dragover { border-color: var(--blue-light); background: rgba(37,99,235,0.09); }
.rp-drop-zone .drop-icon { font-size: 1.6rem; color: var(--blue-light); opacity: 0.5; margin-bottom: 0.3rem; }
.rp-drop-zone .drop-label { font-size: 0.8rem; color: var(--gray-400); }
.rp-drop-zone .drop-label span { color: var(--blue-light); font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
.rp-drop-zone .drop-hint { font-size: 0.68rem; color: var(--gray-600); margin-top: 0.2rem; }
#rpFileList { margin-top: 0.6rem; display: flex; flex-direction: column; gap: 0.3rem; }
.rp-file-chip { display: flex; align-items: center; gap: 0.4rem; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.07); border-radius: 3px; padding: 0.22rem 0.55rem; font-size: 0.73rem; color: var(--white); }
.rp-file-chip i { color: var(--blue-light); flex-shrink: 0; }
.rp-file-chip .file-size { color: var(--gray-500); margin-left: auto; flex-shrink: 0; }
.rp-file-chip .remove-file { background: none; border: none; color: var(--gray-600); cursor: pointer; padding: 0; font-size: 0.7rem; margin-left: 0.4rem; flex-shrink: 0; }
.rp-file-chip .remove-file:hover { color: #ef4444; }

/* Right summary panel */
.rp-summary-panel { padding: 1.5rem 1.25rem; background: rgba(0,0,0,0.25); display: flex; flex-direction: column; gap: 1rem; overflow-y: auto; max-height: 68vh; }
.rp-summary-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 5px; padding: 1rem; }
.rp-summary-title { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--gray-500); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem; }
.rp-summary-title i { color: var(--gold); }
.rp-summary-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; padding: 0.28rem 0; }
.rp-summary-row .s-label { color: var(--gray-400); }
.rp-summary-row .s-val   { font-weight: 600; color: var(--white); }
.rp-summary-row.divider  { border-top: 1px solid rgba(255,255,255,0.06); margin-top: 0.25rem; padding-top: 0.55rem; }
.rp-summary-row.total .s-label { color: var(--white); font-weight: 700; font-size: 0.85rem; }
.rp-summary-row.total .s-val   { color: var(--gold); font-size: 1.05rem; font-weight: 800; }
.rp-comm-val { color: #22c55e !important; }

/* Variance badge on summary */
.variance-badge { font-size: 0.65rem; font-weight: 700; padding: 0.1rem 0.4rem; border-radius: 3px; }
.variance-badge.over  { background: rgba(245,158,11,0.12); color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }
.variance-badge.under { background: rgba(239,68,68,0.12);  color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
.variance-badge.exact { background: rgba(34,197,94,0.1);   color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }

/* Period coverage bar */
.period-progress-wrap { margin-top: 0.25rem; }
.period-progress-label { display: flex; justify-content: space-between; font-size: 0.65rem; color: var(--gray-500); margin-bottom: 0.3rem; }
.period-progress-bar-bg { background: rgba(255,255,255,0.06); border-radius: 4px; height: 6px; overflow: hidden; }
.period-progress-bar-fill { height: 100%; border-radius: 4px; background: linear-gradient(90deg, var(--blue-dark), var(--blue-light)); transition: width 0.4s ease; }

/* Checklist inside summary */
.rp-checklist { display: flex; flex-direction: column; gap: 0.35rem; }
.rp-check-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.76rem; }
.rp-check-item i { font-size: 0.75rem; flex-shrink: 0; }
.rp-check-item.ok   i { color: #22c55e; }
.rp-check-item.warn i { color: #f59e0b; }
.rp-check-item.bad  i { color: #ef4444; }
.rp-check-item.ok   span { color: var(--gray-300); }
.rp-check-item.warn span { color: #f59e0b; }
.rp-check-item.bad  span { color: #ef4444; }
</style>

<div class="modal fade modal-dark" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="overflow:hidden;">
            <form id="recordPaymentForm" enctype="multipart/form-data">
                <input type="hidden" name="rental_id" value="<?= $rental_id ?>">
                <div class="modal-header" style="padding:1.25rem 1.5rem;">
                    <h5 class="modal-title" id="recordPaymentModalLabel">
                        <i class="bi bi-cash-coin"></i> Record Rent Payment
                        <span style="font-size:0.75rem;font-weight:400;color:var(--gray-400);margin-left:0.5rem;"><?= htmlspecialchars($lease['StreetAddress']) ?></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Two-column body -->
                <div class="rp-modal-grid" style="max-height:68vh;">

                    <!-- LEFT: Form inputs -->
                    <div class="rp-form-panel">

                        <!-- Amount -->
                        <p class="rp-section-label"><i class="bi bi-cash-stack"></i> Payment Amount</p>
                        <div class="mb-1">
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" class="form-control" id="rpAmount" name="payment_amount"
                                       min="1" step="0.01" required
                                       value="<?= htmlspecialchars($lease['monthly_rent']) ?>">
                            </div>
                        </div>
                        <div class="quick-chips mb-4" id="rpAmountChips">
                            <button type="button" class="quick-chip active" data-amt="<?= $lease['monthly_rent'] ?>">
                                Monthly rent &#8369;<?= number_format($lease['monthly_rent'], 0) ?>
                            </button>
                            <button type="button" class="quick-chip" data-amt="<?= $lease['monthly_rent'] * 2 ?>">
                                2× &#8369;<?= number_format($lease['monthly_rent'] * 2, 0) ?>
                            </button>
                            <button type="button" class="quick-chip" data-amt="<?= $lease['monthly_rent'] * 3 ?>">
                                3× &#8369;<?= number_format($lease['monthly_rent'] * 3, 0) ?>
                            </button>
                        </div>

                        <!-- Payment date -->
                        <p class="rp-section-label"><i class="bi bi-calendar3"></i> Payment Date</p>
                        <div class="mb-4">
                            <input type="date" class="form-control" id="rpPayDate" name="payment_date"
                                   required value="<?= date('Y-m-d') ?>">
                        </div>

                        <!-- Period -->
                        <p class="rp-section-label">
                            <i class="bi bi-calendar-range"></i> Period Covered
                            <span class="period-auto-badge">auto-fill</span>
                        </p>
                        <div class="row g-2 mb-4">
                            <div class="col-6">
                                <label class="form-label" style="font-size:0.75rem;">Start <span class="req">*</span></label>
                                <input type="date" class="form-control" id="rpPeriodStart" name="period_start"
                                       required value="<?= htmlspecialchars($next_start) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label" style="font-size:0.75rem;">End <span class="req">*</span> <span id="rpAutoFillNote" style="font-size:0.65rem;color:var(--blue-light);opacity:0.8;">auto-filled</span></label>
                                <input type="date" class="form-control" id="rpPeriodEnd" name="period_end"
                                       required value="<?= htmlspecialchars($next_end) ?>">
                            </div>
                        </div>

                        <!-- Documents -->
                        <p class="rp-section-label"><i class="bi bi-paperclip"></i> Proof of Payment <span class="req" style="margin-left:0.2rem;">*</span></p>
                        <div class="rp-drop-zone mb-1" id="rpDropZone">
                            <input type="file" id="rpFileInput" name="payment_documents[]" multiple
                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="display:none;">
                            <div class="drop-icon"><i class="bi bi-cloud-arrow-up-fill"></i></div>
                            <div class="drop-label"><span>Click to browse</span> or drag & drop files here</div>
                            <div class="drop-hint">PDF, JPG, PNG, DOC &mdash; max 10 MB each</div>
                        </div>
                        <div id="rpFileList"></div>
                        <div class="mb-4"></div>

                        <!-- Notes -->
                        <p class="rp-section-label"><i class="bi bi-chat-left-text"></i> Notes <span style="font-weight:400;color:var(--gray-600);text-transform:none;letter-spacing:0;">(optional)</span></p>
                        <textarea class="form-control" name="additional_notes" id="rpNotes" rows="2"
                                  maxlength="2000" placeholder="e.g. GCash ref #123456, bank transfer..."></textarea>
                    </div>

                    <!-- RIGHT: Live summary -->
                    <div class="rp-summary-panel">

                        <!-- Payment breakdown -->
                        <div class="rp-summary-card">
                            <div class="rp-summary-title"><i class="bi bi-calculator-fill"></i> Payment Breakdown</div>
                            <div class="rp-summary-row">
                                <span class="s-label">Monthly Rent</span>
                                <span class="s-val">&#8369;<?= number_format($lease['monthly_rent'], 2) ?></span>
                            </div>
                            <div class="rp-summary-row">
                                <span class="s-label">Amount Entered</span>
                                <span class="s-val" id="srpAmount">&#8369;<?= number_format($lease['monthly_rent'], 2) ?></span>
                            </div>
                            <div class="rp-summary-row">
                                <span class="s-label">vs Monthly Rent</span>
                                <span class="s-val" id="srpVariance">
                                    <span class="variance-badge exact">Exact</span>
                                </span>
                            </div>
                            <div class="rp-summary-row divider">
                                <span class="s-label">Commission Rate</span>
                                <span class="s-val"><?= $lease['commission_rate'] ?>%</span>
                            </div>
                            <div class="rp-summary-row">
                                <span class="s-label">Est. Commission</span>
                                <span class="s-val rp-comm-val" id="srpComm">
                                    &#8369;<?= number_format($lease['monthly_rent'] * $lease['commission_rate'] / 100, 2) ?>
                                </span>
                            </div>
                            <div class="rp-summary-row divider total">
                                <span class="s-label">Payment Total</span>
                                <span class="s-val" id="srpTotal">&#8369;<?= number_format($lease['monthly_rent'], 2) ?></span>
                            </div>
                        </div>

                        <!-- Period coverage -->
                        <div class="rp-summary-card">
                            <div class="rp-summary-title"><i class="bi bi-calendar2-range-fill"></i> Period Coverage</div>
                            <div class="rp-summary-row">
                                <span class="s-label">Start</span>
                                <span class="s-val" id="srpPeriodStart"><?= date('M d, Y', strtotime($next_start)) ?></span>
                            </div>
                            <div class="rp-summary-row">
                                <span class="s-label">End</span>
                                <span class="s-val" id="srpPeriodEnd"><?= date('M d, Y', strtotime($next_end)) ?></span>
                            </div>
                            <div class="rp-summary-row">
                                <span class="s-label">Days Covered</span>
                                <span class="s-val" id="srpDays">—</span>
                            </div>
                            <div class="period-progress-wrap mt-2">
                                <div class="period-progress-label">
                                    <span>0</span><span id="srpProgressLabel">30 days</span>
                                </div>
                                <div class="period-progress-bar-bg">
                                    <div class="period-progress-bar-fill" id="srpProgressBar" style="width:100%;"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Readiness checklist -->
                        <div class="rp-summary-card">
                            <div class="rp-summary-title"><i class="bi bi-clipboard2-check-fill"></i> Readiness Check</div>
                            <div class="rp-checklist" id="srpChecklist">
                                <div class="rp-check-item ok" id="chkAmount">
                                    <i class="bi bi-check-circle-fill"></i><span>Payment amount set</span>
                                </div>
                                <div class="rp-check-item ok" id="chkDate">
                                    <i class="bi bi-check-circle-fill"></i><span>Payment date set</span>
                                </div>
                                <div class="rp-check-item ok" id="chkPeriod">
                                    <i class="bi bi-check-circle-fill"></i><span>Period dates valid</span>
                                </div>
                                <div class="rp-check-item bad" id="chkDocs">
                                    <i class="bi bi-x-circle-fill"></i><span>No documents uploaded</span>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.rp-summary-panel -->
                </div><!-- /.rp-modal-grid -->

                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-submit-gold" id="submitPayBtn">
                        <i class="bi bi-send-fill"></i> Submit Payment Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Renew Lease Modal -->
<div class="modal fade modal-dark" id="renewLeaseModal" tabindex="-1" aria-labelledby="renewLeaseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="renewLeaseForm">
                <input type="hidden" name="rental_id" value="<?= $rental_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="renewLeaseModalLabel">
                        <i class="bi bi-arrow-repeat"></i> Renew Lease
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="modal-info-banner mb-4">
                        <i class="bi bi-calendar-event"></i>
                        <div>Current lease ends: <strong><?= date('M d, Y', strtotime($lease['lease_end_date'])) ?></strong></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Lease Term (Months) <span class="req">*</span></label>
                        <input type="number" class="form-control" name="new_term_months" value="<?= (int)$lease['lease_term_months'] ?>" min="1" max="120" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Monthly Rent (&#8369;) <span class="req">*</span></label>
                        <input type="number" class="form-control" name="new_monthly_rent" value="<?= htmlspecialchars($lease['monthly_rent']) ?>" min="1" step="0.01" required>
                        <div class="form-text">Update if the rent amount has changed for the new term</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-submit-green" id="renewBtn">
                        <i class="bi bi-check-lg"></i> Confirm Renewal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Terminate Lease Modal -->
<div class="modal fade modal-dark" id="terminateLeaseModal" tabindex="-1" aria-labelledby="terminateLeaseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="terminateLeaseForm">
                <input type="hidden" name="rental_id" value="<?= $rental_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="terminateLeaseModalLabel">
                        <i class="bi bi-x-circle" style="color:#ef4444 !important;"></i>
                        <span style="color:#ef4444;">Terminate Lease / Tenant Move-Out</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="modal-warning-banner mb-3">
                        <div class="warning-title"><i class="bi bi-exclamation-triangle-fill"></i> This action will:</div>
                        <ul>
                            <li>Mark the lease as <strong>Terminated</strong></li>
                            <li>Return the property to <strong>For Rent</strong> status</li>
                            <li>Stop future commission accrual for this lease</li>
                        </ul>
                    </div>
                    <p style="font-size:0.82rem;color:var(--gray-400);margin:0;">Pending rent payments (if any) will remain as-is for admin review.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-submit-red" id="terminateBtn">
                        <i class="bi bi-x-lg"></i> Confirm Termination
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'logout_agent_modal.php'; ?>
<div id="toastContainer"></div>

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
<script>
// ===== TOAST =====
function showToast(type, title, message, duration) {
    duration = duration || 4500;
    var container = document.getElementById('toastContainer');
    var icons = { success: 'bi-check-circle-fill', error: 'bi-x-octagon-fill', info: 'bi-info-circle-fill' };
    var toast = document.createElement('div');
    toast.className = 'app-toast toast-' + type;
    toast.innerHTML =
        '<div class="app-toast-icon"><i class="bi ' + (icons[type] || icons.info) + '"></i></div>' +
        '<div class="app-toast-body"><div class="app-toast-title">' + title + '</div><div class="app-toast-msg">' + message + '</div></div>' +
        '<button class="app-toast-close" onclick="dismissToast(this.closest(\'.app-toast\'))">&times;</button>' +
        '<div class="app-toast-progress" style="animation: toast-progress ' + duration + 'ms linear forwards;"></div>';
    container.appendChild(toast);
    var timer = setTimeout(function() { dismissToast(toast); }, duration);
    toast._timer = timer;
}
function dismissToast(toast) {
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    clearTimeout(toast._timer);
    toast.classList.add('toast-out');
    setTimeout(function() { toast.remove(); }, 320);
}

// ===== RECORD PAYMENT =====
document.getElementById('recordPaymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fileInput = document.getElementById('rpFileInput');
    if (!fileInput.files || fileInput.files.length === 0) {
        showToast('error', 'Missing Documents', 'Please upload at least one proof of payment document.');
        document.getElementById('rpDropZone').classList.add('dragover');
        setTimeout(function() { document.getElementById('rpDropZone').classList.remove('dragover'); }, 1500);
        return;
    }
    var btn = document.getElementById('submitPayBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Submitting...';
    fetch('record_rental_payment.php', { method: 'POST', body: new FormData(this) })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('recordPaymentModal')).hide();
                showToast('success', 'Payment Submitted', data.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showToast('error', 'Submission Failed', data.message || 'An error occurred.');
            }
        })
        .catch(function() { showToast('error', 'Error', 'Could not connect. Please try again.'); })
        .finally(function() { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill"></i> Submit Payment Record'; });
});

// ===== SMART RECORD PAYMENT MODAL =====
(function () {
    var MONTHLY_RENT  = <?= (float)$lease['monthly_rent'] ?>;
    var COMM_RATE     = <?= (float)$lease['commission_rate'] ?>;

    var rpAmount      = document.getElementById('rpAmount');
    var rpPayDate     = document.getElementById('rpPayDate');
    var rpPeriodStart = document.getElementById('rpPeriodStart');
    var rpPeriodEnd   = document.getElementById('rpPeriodEnd');
    var rpFileInput   = document.getElementById('rpFileInput');
    var rpDropZone    = document.getElementById('rpDropZone');
    var rpFileList    = document.getElementById('rpFileList');

    // Summary els
    var srpAmount       = document.getElementById('srpAmount');
    var srpVariance     = document.getElementById('srpVariance');
    var srpComm         = document.getElementById('srpComm');
    var srpTotal        = document.getElementById('srpTotal');
    var srpPeriodStart  = document.getElementById('srpPeriodStart');
    var srpPeriodEnd    = document.getElementById('srpPeriodEnd');
    var srpDays         = document.getElementById('srpDays');
    var srpProgressBar  = document.getElementById('srpProgressBar');
    var srpProgressLabel= document.getElementById('srpProgressLabel');
    var chkAmount       = document.getElementById('chkAmount');
    var chkDate         = document.getElementById('chkDate');
    var chkPeriod       = document.getElementById('chkPeriod');
    var chkDocs         = document.getElementById('chkDocs');

    var selectedFiles = [];

    /* --- Formatters --- */
    function fmt(n) { return '₱' + parseFloat(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); }
    function fmtDate(d) {
        if (!d) return '—';
        var dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('en-PH', { month:'short', day:'2-digit', year:'numeric' });
    }
    function setCheck(el, state, label) {
        var icons = { ok:'bi-check-circle-fill', warn:'bi-exclamation-circle-fill', bad:'bi-x-circle-fill' };
        el.className = 'rp-check-item ' + state;
        el.querySelector('i').className = 'bi ' + icons[state];
        el.querySelector('span').textContent = label;
    }

    /* --- Auto-fill period end when start changes --- */
    function autoFillPeriodEnd() {
        var s = rpPeriodStart.value;
        if (!s) return;
        var dt = new Date(s + 'T00:00:00');
        dt.setMonth(dt.getMonth() + 1);
        dt.setDate(dt.getDate() - 1);
        var y = dt.getFullYear();
        var m = String(dt.getMonth() + 1).padStart(2, '0');
        var d = String(dt.getDate()).padStart(2, '0');
        rpPeriodEnd.value = y + '-' + m + '-' + d;
        document.getElementById('rpAutoFillNote').style.opacity = '1';
        setTimeout(function() { document.getElementById('rpAutoFillNote').style.opacity = '0.5'; }, 800);
        updateSummary();
    }

    /* --- Main summary updater --- */
    function updateSummary() {
        var amount = parseFloat(rpAmount.value) || 0;
        var comm   = amount * COMM_RATE / 100;
        var diff   = amount - MONTHLY_RENT;

        /* Amount & commission */
        srpAmount.textContent = fmt(amount);
        srpComm.textContent   = fmt(comm);
        srpTotal.textContent  = fmt(amount);

        /* Variance badge */
        var badge = '';
        if (Math.abs(diff) < 0.01) {
            badge = '<span class="variance-badge exact">Exact</span>';
        } else if (diff > 0) {
            badge = '<span class="variance-badge over">+' + fmt(diff) + ' over</span>';
        } else {
            badge = '<span class="variance-badge under">' + fmt(diff) + ' short</span>';
        }
        srpVariance.innerHTML = badge;

        /* Period dates */
        var ps = rpPeriodStart.value;
        var pe = rpPeriodEnd.value;
        srpPeriodStart.textContent = ps ? fmtDate(ps) : '—';
        srpPeriodEnd.textContent   = pe ? fmtDate(pe) : '—';

        /* Days covered & progress bar */
        var days = 0;
        var periodOk = false;
        if (ps && pe) {
            var dps = new Date(ps + 'T00:00:00');
            var dpe = new Date(pe + 'T00:00:00');
            days = Math.round((dpe - dps) / (1000 * 60 * 60 * 24)) + 1;
            periodOk = days > 0;
        }
        srpDays.textContent = days > 0 ? days + ' day' + (days !== 1 ? 's' : '') : '—';
        var pct = Math.min(100, Math.max(0, (days / 31) * 100));
        srpProgressBar.style.width = pct + '%';
        srpProgressLabel.textContent = days > 0 ? days + ' days' : '—';

        /* Checklist */
        setCheck(chkAmount, amount > 0 ? 'ok' : 'bad', amount > 0 ? 'Payment amount set' : 'Enter payment amount');
        setCheck(chkDate,   rpPayDate.value ? 'ok' : 'bad', rpPayDate.value ? 'Payment date set' : 'Set payment date');
        setCheck(chkPeriod, periodOk ? 'ok' : 'bad', periodOk ? 'Period dates valid' : 'Check period dates');
        var fileOk = selectedFiles.length > 0;
        setCheck(chkDocs,   fileOk ? 'ok' : 'bad', fileOk ? selectedFiles.length + ' document(s) attached' : 'No documents uploaded');
    }

    /* --- Quick amount chips --- */
    document.querySelectorAll('#rpAmountChips .quick-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            rpAmount.value = parseFloat(this.dataset.amt).toFixed(2);
            document.querySelectorAll('#rpAmountChips .quick-chip').forEach(function(c) { c.classList.remove('active'); });
            this.classList.add('active');
            updateSummary();
        });
    });

    /* Deactivate chips when amount is manually typed */
    rpAmount.addEventListener('input', function() {
        var v = parseFloat(this.value);
        document.querySelectorAll('#rpAmountChips .quick-chip').forEach(function(c) {
            c.classList.toggle('active', Math.abs(parseFloat(c.dataset.amt) - v) < 0.01);
        });
        updateSummary();
    });

    rpPayDate.addEventListener('change', updateSummary);

    rpPeriodStart.addEventListener('change', autoFillPeriodEnd);
    rpPeriodEnd.addEventListener('change', function() {
        document.getElementById('rpAutoFillNote').style.opacity = '0';
        updateSummary();
    });

    /* --- File drop zone --- */
    rpDropZone.addEventListener('click', function() { rpFileInput.click(); });

    rpDropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('dragover'); });
    rpDropZone.addEventListener('dragleave', function() { this.classList.remove('dragover'); });
    rpDropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        handleFiles(Array.from(e.dataTransfer.files));
    });

    rpFileInput.addEventListener('change', function() {
        var files = Array.from(this.files);
        this.value = '';
        handleFiles(files);
    });

    function fmtFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function handleFiles(files) {
        files.forEach(function(f) {
            if (!selectedFiles.find(function(x) { return x.name === f.name && x.size === f.size; })) {
                selectedFiles.push(f);
            }
        });
        renderFileList();
        syncFileInput();
        updateSummary();
    }

    function renderFileList() {
        rpFileList.innerHTML = '';
        selectedFiles.forEach(function(f, i) {
            var ext = f.name.split('.').pop().toLowerCase();
            var iconMap = { pdf:'bi-file-earmark-pdf-fill', jpg:'bi-file-earmark-image-fill', jpeg:'bi-file-earmark-image-fill', png:'bi-file-earmark-image-fill', doc:'bi-file-earmark-word-fill', docx:'bi-file-earmark-word-fill' };
            var icon = iconMap[ext] || 'bi-file-earmark-fill';
            var chip = document.createElement('div');
            chip.className = 'rp-file-chip';
            chip.innerHTML = '<i class="bi ' + icon + '"></i><span>' + f.name + '</span><span class="file-size">' + fmtFileSize(f.size) + '</span><button type="button" class="remove-file" data-idx="' + i + '" title="Remove"><i class="bi bi-x-lg"></i></button>';
            rpFileList.appendChild(chip);
        });
        rpFileList.querySelectorAll('.remove-file').forEach(function(btn) {
            btn.addEventListener('click', function() {
                selectedFiles.splice(parseInt(this.dataset.idx), 1);
                renderFileList();
                syncFileInput();
                updateSummary();
            });
        });
    }

    function syncFileInput() {
        /* Rebuild DataTransfer so FormData picks up the curated list */
        var dt = new DataTransfer();
        selectedFiles.forEach(function(f) { dt.items.add(f); });
        rpFileInput.files = dt.files;
    }

    /* --- Reset modal on open --- */
    document.getElementById('recordPaymentModal').addEventListener('show.bs.modal', function() {
        selectedFiles = [];
        rpFileList.innerHTML = '';
        syncFileInput();
        updateSummary();
    });

    /* --- Initial render --- */
    updateSummary();
    /* Compute initial days */
    (function() {
        var ps = rpPeriodStart.value;
        var pe = rpPeriodEnd.value;
        if (ps && pe) {
            var d = Math.round((new Date(pe + 'T00:00:00') - new Date(ps + 'T00:00:00')) / 86400000) + 1;
            srpDays.textContent = d + ' day' + (d !== 1 ? 's' : '');
            srpProgressBar.style.width = Math.min(100, (d / 31) * 100) + '%';
            srpProgressLabel.textContent = d + ' days';
        }
    }());
}());

// ===== RENEW LEASE =====
document.getElementById('renewLeaseForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('renewBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Processing...';
    fetch('renew_lease.php', { method: 'POST', body: new FormData(this) })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('renewLeaseModal')).hide();
                showToast('success', 'Lease Renewed', data.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showToast('error', 'Renewal Failed', data.message || 'An error occurred.');
            }
        })
        .catch(function() { showToast('error', 'Error', 'Could not connect. Please try again.'); })
        .finally(function() { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i> Confirm Renewal'; });
});

// ===== TERMINATE LEASE =====
document.getElementById('terminateLeaseForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('terminateBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Processing...';
    fetch('terminate_lease.php', { method: 'POST', body: new FormData(this) })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('terminateLeaseModal')).hide();
                showToast('success', 'Lease Terminated', data.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showToast('error', 'Termination Failed', data.message || 'An error occurred.');
            }
        })
        .catch(function() { showToast('error', 'Error', 'Could not connect. Please try again.'); })
        .finally(function() { btn.disabled = false; btn.innerHTML = '<i class="bi bi-x-lg"></i> Confirm Termination'; });
});
</script>

<!-- SKELETON HYDRATION — Progressive Content Reveal -->
<script>
(function () {
    'use strict';
    var MIN_SKELETON_MS = 400;
    var skeletonStart   = Date.now();

    function hydrate() {
        var sk = document.getElementById('sk-screen');
        var pc = document.getElementById('page-content');
        if (!pc) return;
        if (!sk) { pc.style.cssText = 'display:block;opacity:1;'; return; }
        pc.style.display  = 'block';
        pc.style.opacity  = '0';
        requestAnimationFrame(function () {
            sk.style.transition = 'opacity 0.35s ease';
            sk.style.opacity    = '0';
            pc.style.transition = 'opacity 0.42s ease 0.1s';
            requestAnimationFrame(function () { pc.style.opacity = '1'; });
        });
        setTimeout(function () {
            if (sk && sk.parentNode) sk.parentNode.removeChild(sk);
            pc.style.transition = '';
            pc.style.opacity    = '';
            document.dispatchEvent(new CustomEvent('skeleton:hydrated'));
        }, 520);
    }

    function schedule() {
        var elapsed   = Date.now() - skeletonStart;
        var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);
        remaining > 0 ? setTimeout(hydrate, remaining) : hydrate();
    }

    if (document.readyState === 'complete') {
        schedule();
    } else {
        window.addEventListener('load', schedule);
    }
}());
</script>

<!-- TOAST TRIGGERS — fire after skeleton fades out -->
<script>
document.addEventListener('skeleton:hydrated', function () {
    <?php if ($sk_toast_msg): ?>
    showToast('<?= $sk_toast_type ?>', '<?= $sk_toast_title ?>', '<?= $sk_toast_msg ?>', 5000);
    <?php endif; ?>
});
</script>
</body>
</html>
