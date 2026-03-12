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

// Get rental_id from query param (or property_id)
$rental_id = isset($_GET['rental_id']) ? (int)$_GET['rental_id'] : 0;
$property_id_param = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;

// If property_id given, find rental_id (admin: no agent restriction)
if ($rental_id <= 0 && $property_id_param > 0) {
    $find = $conn->prepare("SELECT rental_id FROM finalized_rentals WHERE property_id = ? AND lease_status IN ('Active','Renewed','Expired') ORDER BY finalized_at DESC LIMIT 1");
    $find->bind_param("i", $property_id_param);
    $find->execute();
    $fr = $find->get_result()->fetch_assoc();
    if ($fr) $rental_id = (int)$fr['rental_id'];
    $find->close();
}

if ($rental_id <= 0) {
    header("Location: admin_rental_payments.php");
    exit();
}

// Fetch lease details (admin: no agent_id filter)
$lease_stmt = $conn->prepare("
    SELECT fr.*, p.StreetAddress, p.City, p.Barangay, p.Province, p.PropertyType,
           a.first_name AS agent_first, a.last_name AS agent_last, a.email AS agent_email,
           (SELECT PhotoURL FROM property_images WHERE property_ID = p.property_ID AND SortOrder = 1 LIMIT 1) AS thumb
    FROM finalized_rentals fr
    JOIN property p ON fr.property_id = p.property_ID
    JOIN accounts a ON fr.agent_id = a.account_id
    WHERE fr.rental_id = ?
");
$lease_stmt->bind_param("i", $rental_id);
$lease_stmt->execute();
$lease = $lease_stmt->get_result()->fetch_assoc();
$lease_stmt->close();

if (!$lease) {
    header("Location: admin_rental_payments.php");
    exit();
}

$property_id = (int) $lease['property_id'];

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
$rejected_count = count(array_filter($payments, fn($p) => $p['status'] === 'Rejected'));
$total_revenue = array_sum(array_column(array_filter($payments, fn($p) => $p['status'] === 'Confirmed'), 'payment_amount'));
$total_commission = array_sum(array_column(array_filter($payments, fn($p) => in_array($p['commission_status'], ['calculated', 'paid'])), 'commission_amount'));
$is_active = in_array($lease['lease_status'], ['Active', 'Renewed']);

$agent_name = trim($lease['agent_first'] . ' ' . $lease['agent_last']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="images/Logo.png" type="image/png">
    <link rel="shortcut icon" href="images/Logo.png" type="image/png">
    <title>Lease Management - <?= htmlspecialchars($lease['StreetAddress']) ?></title>
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
            --gold: #d4af37; --gold-light: #f4d03f; --gold-dark: #b8941f;
            --blue: #2563eb; --blue-light: #3b82f6; --blue-dark: #1e40af;
            --bg-light: #f8f9fa; --border-color: #e0e0e0;
            --card-bg: #ffffff; --text-primary: #212529; --text-secondary: #6c757d;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--bg-light); color: var(--text-primary); line-height: 1.6; }
        .admin-content { margin-left: 290px; padding: 0; min-height: 100vh; max-width: 1800px; }
        .lm-body-content { padding: 0 2rem 2rem; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; } .lm-body-content { padding: 0 1.5rem 1.5rem; } }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.35); border-radius: 3px; }

        /* ===== HERO BANNER ===== */
        .lm-hero { position: relative; height: 280px; overflow: hidden; background: #1e293b; margin-bottom: 1.5rem; }
        .lm-hero-bg { width: 100%; height: 100%; object-fit: cover; object-position: center 30%; display: block; }
        .lm-hero-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(15,23,42,0.35) 0%, rgba(15,23,42,0.55) 40%, rgba(15,23,42,0.92) 100%); }
        .lm-hero-top-line { position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); z-index: 3; }
        .lm-hero-back { position: absolute; top: 1.25rem; left: 2rem; z-index: 5; display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.78rem; font-weight: 600; color: rgba(255,255,255,0.7); text-decoration: none; background: rgba(0,0,0,0.3); padding: 0.35rem 0.85rem; border-radius: 4px; backdrop-filter: blur(6px); transition: all 0.2s; }
        .lm-hero-back:hover { color: #fff; background: rgba(0,0,0,0.5); }
        .lm-hero-type-badge { position: absolute; top: 1.25rem; right: 2rem; z-index: 5; background: rgba(0,0,0,0.55); color: #e2e8f0; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 0.28rem 0.7rem; border-radius: 3px; backdrop-filter: blur(6px); }
        .lm-hero-content { position: absolute; bottom: 0; left: 0; right: 0; padding: 0 2rem 1.5rem; z-index: 4; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem; }
        .lm-hero-info { flex: 1; min-width: 280px; }
        .lm-hero-title { font-size: 1.65rem; font-weight: 800; color: #fff; margin: 0 0 0.2rem; line-height: 1.25; text-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .lm-hero-addr { font-size: 0.82rem; color: rgba(255,255,255,0.6); display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.65rem; }
        .lm-hero-addr i { font-size: 0.72rem; color: var(--gold); }
        .lm-hero-badges { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .lm-hero-price-block { text-align: right; flex-shrink: 0; }
        .lm-hero-rent { font-size: 1.75rem; font-weight: 900; background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.4)); line-height: 1.2; }
        .lm-hero-rent span { font-size: 0.82rem; font-weight: 600; opacity: 0.8; }
        .lm-hero-rent-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: rgba(255,255,255,0.45); margin-top: 0.1rem; }

        /* Lease status badge */
        .lease-status-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.22rem 0.65rem; border-radius: 3px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .lease-status-badge.active     { background: rgba(34,197,94,0.15);  color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }
        .lease-status-badge.renewed    { background: rgba(37,99,235,0.15);  color: #93c5fd; border: 1px solid rgba(37,99,235,0.3); }
        .lease-status-badge.terminated { background: rgba(239,68,68,0.15);  color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
        .lease-status-badge.expired    { background: rgba(245,158,11,0.15); color: #fcd34d; border: 1px solid rgba(245,158,11,0.3); }

        /* Meta badges */
        .lm-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.65rem; border-radius: 3px; font-size: 0.72rem; font-weight: 600; backdrop-filter: blur(4px); }
        .lm-badge i { font-size: 0.68rem; }
        .lm-badge.blue  { background: rgba(37,99,235,0.15); color: #93c5fd; border: 1px solid rgba(37,99,235,0.25); }
        .lm-badge.gold  { background: rgba(212,175,55,0.15); color: var(--gold-light); border: 1px solid rgba(212,175,55,0.25); }
        .lm-badge.teal  { background: rgba(20,184,166,0.15); color: #5eead4; border: 1px solid rgba(20,184,166,0.25); }

        /* ===== ACTION BAR (below hero) ===== */
        .lm-action-bar { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 0.85rem 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; position: relative; overflow: hidden; }
        .lm-action-bar::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .lm-action-bar-left { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
        .lm-action-bar-right { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .lm-ab-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.65rem; border-radius: 3px; font-size: 0.72rem; font-weight: 600; }
        .lm-ab-badge i { font-size: 0.68rem; }
        .lm-ab-badge.blue  { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .lm-ab-badge.gold  { background: rgba(212,175,55,0.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); }
        .lm-ab-badge.teal  { background: rgba(20,184,166,0.08); color: #0d9488; border: 1px solid rgba(20,184,166,0.2); }
        .btn-action { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 0.9rem; border: none; border-radius: 4px; font-size: 0.78rem; font-weight: 700; cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em; transition: all 0.3s; position: relative; overflow: hidden; }
        .btn-action::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.18), transparent); transition: left 0.45s ease; }
        .btn-action:hover::before { left: 100%; }
        .btn-action.gold  { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #0a0a0a; }
        .btn-action.gold:hover { box-shadow: 0 4px 16px rgba(212,175,55,0.4); transform: translateY(-1px); }
        .btn-action.green { background: linear-gradient(135deg, #15803d, #16a34a); color: #fff; }
        .btn-action.green:hover { box-shadow: 0 4px 16px rgba(22,163,74,0.3); transform: translateY(-1px); }
        .btn-action.red   { background: linear-gradient(135deg, #b91c1c, #dc2626); color: #fff; }
        .btn-action.red:hover { box-shadow: 0 4px 16px rgba(220,38,38,0.3); transform: translateY(-1px); }

        /* ===== KPI GRID ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1.15rem; display: flex; flex-direction: column; transition: all 0.3s ease; position: relative; overflow: hidden; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--blue), transparent); opacity: 0; transition: opacity 0.3s ease; }
        .kpi-card:hover { border-color: rgba(37,99,235,0.25); box-shadow: 0 8px 32px rgba(37,99,235,0.08); transform: translateY(-3px); }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-icon { width: 38px; height: 38px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1rem; margin-bottom: 0.65rem; flex-shrink: 0; }
        .kpi-icon.green { background: linear-gradient(135deg, rgba(34,197,94,0.06), rgba(34,197,94,0.12));  color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .kpi-icon.amber { background: linear-gradient(135deg, rgba(245,158,11,0.06), rgba(245,158,11,0.12)); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .kpi-icon.red   { background: linear-gradient(135deg, rgba(239,68,68,0.06), rgba(239,68,68,0.12));   color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }
        .kpi-icon.blue  { background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(37,99,235,0.12));   color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .kpi-icon.gold  { background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.15)); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); }
        .kpi-label { font-size: 0.66rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 0.2rem; }
        .kpi-value { font-size: 1.45rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }
        .kpi-value.kpi-currency { font-size: 1.15rem; }

        /* ===== SECTION CARD ===== */
        .section-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .section-card-header { display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 0.85rem 1.5rem; position: relative; overflow: hidden; flex-shrink: 0; }
        .section-card-header::before { content: ''; position: absolute; top: 0; left: -100%; width: 200%; height: 100%; background: linear-gradient(90deg, transparent, rgba(212,175,55,0.06), transparent); animation: lm-sweep 4s ease-in-out infinite; pointer-events: none; }
        @keyframes lm-sweep { 0% { left: -100%; } 100% { left: 100%; } }
        .section-card-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .section-card-header h6 { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: rgba(255,255,255,0.9); margin: 0; display: flex; align-items: center; gap: 0.5rem; position: relative; z-index: 1; }
        .section-card-header h6 i { color: var(--gold); }
        .section-card-header .sc-header-right { position: relative; z-index: 1; display: flex; align-items: center; gap: 0.5rem; }
        .section-card-body { padding: 1.5rem; }

        /* ===== LEASE INFO GRID ===== */
        .lease-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 0.75rem; }
        .lease-info-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.75rem 1rem; transition: all 0.2s; }
        .lease-info-item:hover { border-color: rgba(37,99,235,0.2); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
        .lease-info-item .label { font-size: 0.64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-secondary); margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.3rem; }
        .lease-info-item .label i { color: var(--gold-dark); font-size: 0.7rem; }
        .lease-info-item .value { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); }
        .lease-info-item .value.gold-val  { color: var(--gold-dark); }
        .lease-info-item .value.green-val { color: #16a34a; }
        .lease-info-item .value.blue-val  { color: var(--blue); }

        /* ===== 2-COLUMN MAIN LAYOUT ===== */
        .lm-main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; align-items: start; }
        @media (max-width: 992px) { .lm-main-grid { grid-template-columns: 1fr; } }

        /* ===== LEASE PROGRESS CARD ===== */
        .lm-progress-wrap { margin-bottom: 1.1rem; }
        .lm-progress-label { display: flex; justify-content: space-between; font-size: 0.72rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.45rem; }
        .lm-progress-track { background: #e2e8f0; border-radius: 6px; height: 10px; overflow: hidden; position: relative; }
        .lm-progress-fill { height: 100%; border-radius: 6px; background: linear-gradient(90deg, var(--blue-dark), var(--blue-light)); transition: width 0.8s cubic-bezier(0.16,1,0.3,1); position: relative; }
        .lm-progress-fill::after { content: ''; position: absolute; top: 0; right: 0; bottom: 0; width: 30px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25)); border-radius: 0 6px 6px 0; }
        .lm-progress-fill.warn    { background: linear-gradient(90deg, #d97706, #f59e0b); }
        .lm-progress-fill.expired { background: linear-gradient(90deg, #b91c1c, #ef4444); }
        .lm-progress-pct { font-size: 0.72rem; font-weight: 700; margin-top: 0.35rem; text-align: right; }
        .lm-progress-pct.blue    { color: var(--blue); }
        .lm-progress-pct.warn    { color: #d97706; }
        .lm-progress-pct.expired { color: #dc2626; }

        /* Stat rows */
        .lm-stat-row { display: flex; align-items: center; justify-content: space-between; padding: 0.55rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.82rem; }
        .lm-stat-row:last-child { border-bottom: none; }
        .lm-stat-row .s-lbl { color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem; }
        .lm-stat-row .s-lbl i { font-size: 0.72rem; color: var(--gold-dark); }
        .lm-stat-row .s-val { font-weight: 700; color: var(--text-primary); }
        .lm-stat-row .s-val.green { color: #16a34a; }
        .lm-stat-row .s-val.gold  { color: var(--gold-dark); }
        .lm-stat-row .s-val.blue  { color: var(--blue); }
        .lm-stat-row .s-val.red   { color: #dc2626; }

        /* Revenue highlight strip */
        .lm-revenue-strip { background: linear-gradient(135deg, rgba(212,175,55,0.05), rgba(212,175,55,0.1)); border: 1px solid rgba(212,175,55,0.2); border-radius: 6px; padding: 0.85rem 1rem; display: flex; align-items: center; justify-content: space-between; margin-top: 1rem; }
        .lm-revenue-strip .rev-lbl { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--gold-dark); margin-bottom: 0.15rem; display: flex; align-items: center; gap: 0.3rem; }
        .lm-revenue-strip .rev-val { font-size: 1.3rem; font-weight: 900; color: var(--gold-dark); }
        .lm-revenue-strip i.rev-icon { font-size: 1.6rem; color: rgba(212,175,55,0.25); }

        /* ===== PAYMENT FILTER TABS ===== */
        .pay-filter-tabs { display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .pay-filter-tab { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.7rem; border-radius: 3px; font-size: 0.68rem; font-weight: 600; cursor: pointer; border: none; background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.5); transition: all 0.2s; position: relative; z-index: 1; }
        .pay-filter-tab:hover { background: rgba(255,255,255,0.12); color: rgba(255,255,255,0.8); }
        .pay-filter-tab.active { background: rgba(212,175,55,0.2); color: var(--gold-light); border: 1px solid rgba(212,175,55,0.3); }
        .pay-filter-tab .tab-count { background: rgba(255,255,255,0.1); padding: 0.05rem 0.35rem; border-radius: 2px; font-size: 0.62rem; }
        .pay-filter-tab.active .tab-count { background: rgba(212,175,55,0.3); }

        /* ===== PAYMENT TABLE ===== */
        .table-responsive { overflow-x: auto; }
        .payment-table { width: 100%; margin: 0; border-collapse: collapse; }
        .payment-table thead tr { background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-bottom: 2px solid #e2e8f0; }
        .payment-table th { padding: 0.75rem 1.25rem; font-size: 0.67rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); white-space: nowrap; }
        .payment-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: all 0.15s; }
        .payment-table tbody tr:last-child { border-bottom: none; }
        .payment-table tbody tr:hover { background: rgba(37,99,235,0.025); }
        .payment-table tbody tr.pay-hidden { display: none; }
        .payment-table td { padding: 0.9rem 1.25rem; font-size: 0.875rem; color: var(--text-primary); vertical-align: middle; }
        .payment-table td.muted { color: var(--text-secondary); font-size: 0.82rem; }
        .pay-idx { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; background: linear-gradient(135deg, #f1f5f9, #e9edf2); border: 1px solid #e2e8f0; border-radius: 5px; font-size: 0.68rem; font-weight: 700; color: var(--text-secondary); }
        .pay-amount-val { font-weight: 800; background: linear-gradient(135deg, var(--gold-dark), var(--gold)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 0.95rem; }
        .pay-comm-val { color: #16a34a; font-weight: 700; font-size: 0.875rem; display: flex; align-items: center; gap: 0.3rem; }
        .pay-comm-dot { width: 6px; height: 6px; background: #16a34a; border-radius: 50%; flex-shrink: 0; }

        /* Payment status badges */
        .pay-badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.6rem; border-radius: 2px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .pay-badge.pending   { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .pay-badge.confirmed { background: rgba(34,197,94,0.1);  color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .pay-badge.rejected  { background: rgba(239,68,68,0.1);  color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 4rem 2rem; }
        .empty-state i { font-size: 2.75rem; color: var(--text-secondary); opacity: 0.3; margin-bottom: 0.75rem; display: block; }
        .empty-state h5 { font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; }
        .empty-state p { color: var(--text-secondary); margin: 0; font-size: 0.875rem; }

        /* ===== MODALS — Admin Light Theme ===== */
        .modal-admin .modal-content {
            background: var(--card-bg); border: 1px solid rgba(37,99,235,0.12);
            box-shadow: 0 20px 60px rgba(0,0,0,0.15); color: var(--text-primary); border-radius: 6px;
        }
        .modal-admin .modal-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-bottom: none; padding: 1.25rem 1.5rem; color: #fff; position: relative; overflow: hidden;
        }
        .modal-admin .modal-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); }
        .modal-admin .modal-title { font-weight: 700; display: flex; align-items: center; gap: 0.5rem; color: #fff; }
        .modal-admin .modal-title i { color: var(--gold); }
        .modal-admin .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .modal-admin .modal-body { padding: 1.5rem; overflow-y: auto; max-height: calc(100vh - 210px); background: #f8fafc; }
        .modal-admin .modal-body::-webkit-scrollbar { width: 6px; }
        .modal-admin .modal-body::-webkit-scrollbar-track { background: #f1f1f1; }
        .modal-admin .modal-body::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.35); border-radius: 3px; }
        .modal-admin .modal-footer { border-top: 1px solid #e2e8f0; padding: 1rem 1.5rem; background: #fff; }
        .modal-admin .form-label { font-weight: 600; font-size: 0.85rem; color: var(--text-primary); }
        .modal-admin .form-label .req { color: #dc2626; }
        .modal-admin .form-control,
        .modal-admin .form-select {
            background: #fff; border: 1px solid #e2e8f0;
            color: var(--text-primary); border-radius: 4px; padding: 0.6rem 0.8rem; font-size: 0.9rem; transition: all 0.3s;
        }
        .modal-admin .form-control:focus,
        .modal-admin .form-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); background: #fff; outline: none; }
        .modal-admin .form-control::placeholder { color: #94a3b8; }
        .modal-admin .form-text { color: var(--text-secondary); font-size: 0.78rem; }
        .modal-admin .input-group-text {
            background: rgba(212,175,55,0.08); border: 1px solid #e2e8f0; color: var(--gold-dark); font-weight: 700;
        }
        /* Modal info / warning banners */
        .modal-info-banner { background: rgba(37,99,235,0.06); border: 1px solid rgba(37,99,235,0.15); border-radius: 4px; padding: 0.6rem 0.9rem; font-size: 0.82rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
        .modal-info-banner i { color: var(--blue); flex-shrink: 0; }
        .modal-warning-banner { background: rgba(239,68,68,0.06); border: 1px solid rgba(239,68,68,0.15); border-radius: 4px; padding: 0.75rem 1rem; font-size: 0.84rem; color: var(--text-primary); }
        .modal-warning-banner .warning-title { font-weight: 700; color: #dc2626; display: flex; align-items: center; gap: 0.4rem; margin-bottom: 0.4rem; }
        .modal-warning-banner ul { margin: 0; padding-left: 1.25rem; }
        .modal-warning-banner li { margin-bottom: 0.2rem; }
        /* Modal buttons */
        .btn-modal-cancel { background: #fff; border: 1px solid #e2e8f0; color: var(--text-secondary); padding: 0.55rem 1rem; border-radius: 4px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-modal-cancel:hover { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.03); }
        .btn-modal-submit-gold  { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #0a0a0a; border: none; padding: 0.55rem 1.1rem; border-radius: 4px; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-modal-submit-gold:hover  { box-shadow: 0 4px 16px rgba(212,175,55,0.35); transform: translateY(-1px); }
        .btn-modal-submit-gold:disabled  { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-modal-submit-green { background: linear-gradient(135deg, #15803d, #16a34a); color: #fff; border: none; padding: 0.55rem 1.1rem; border-radius: 4px; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-modal-submit-green:hover { box-shadow: 0 4px 16px rgba(22,163,74,0.3); transform: translateY(-1px); }
        .btn-modal-submit-green:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-modal-submit-red   { background: linear-gradient(135deg, #b91c1c, #dc2626); color: #fff; border: none; padding: 0.55rem 1.1rem; border-radius: 4px; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-modal-submit-red:hover   { box-shadow: 0 4px 16px rgba(220,38,38,0.3); transform: translateY(-1px); }
        .btn-modal-submit-red:disabled   { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ===== RECORD PAYMENT MODAL EXTRAS ===== */
        .rp-modal-grid { display: grid; grid-template-columns: 1fr 320px; gap: 0; }
        @media (max-width: 768px) { .rp-modal-grid { grid-template-columns: 1fr; } }
        .rp-form-panel { padding: 1.5rem; border-right: 1px solid #e2e8f0; overflow-y: auto; max-height: 68vh; }
        .rp-form-panel::-webkit-scrollbar { width: 5px; }
        .rp-form-panel::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.25); border-radius: 3px; }
        .rp-section-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-secondary); margin: 0 0 0.7rem; padding-bottom: 0.35rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 0.4rem; }
        .rp-section-label i { color: var(--gold-dark); }
        .quick-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem; }
        .quick-chip { padding: 0.2rem 0.6rem; border-radius: 3px; font-size: 0.72rem; font-weight: 600; cursor: pointer; border: 1px solid rgba(212,175,55,0.25); background: rgba(212,175,55,0.06); color: var(--gold-dark); transition: all 0.18s; white-space: nowrap; }
        .quick-chip:hover { background: rgba(212,175,55,0.14); border-color: rgba(212,175,55,0.5); }
        .quick-chip.active { background: rgba(212,175,55,0.18); border-color: var(--gold); }
        .period-auto-badge { font-size: 0.65rem; font-weight: 600; padding: 0.1rem 0.4rem; border-radius: 2px; background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); vertical-align: middle; margin-left: 0.35rem; }
        .rp-drop-zone { border: 1.5px dashed rgba(37,99,235,0.25); border-radius: 5px; padding: 1rem 1.25rem; text-align: center; cursor: pointer; transition: all 0.22s; background: rgba(37,99,235,0.03); }
        .rp-drop-zone:hover, .rp-drop-zone.dragover { border-color: var(--blue); background: rgba(37,99,235,0.06); }
        .rp-drop-zone .drop-icon { font-size: 1.6rem; color: var(--blue); opacity: 0.5; margin-bottom: 0.3rem; }
        .rp-drop-zone .drop-label { font-size: 0.8rem; color: var(--text-secondary); }
        .rp-drop-zone .drop-label span { color: var(--blue); font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
        .rp-drop-zone .drop-hint { font-size: 0.68rem; color: #94a3b8; margin-top: 0.2rem; }
        #rpFileList { margin-top: 0.6rem; display: flex; flex-direction: column; gap: 0.3rem; }
        .rp-file-chip { display: flex; align-items: center; gap: 0.4rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 3px; padding: 0.22rem 0.55rem; font-size: 0.73rem; color: var(--text-primary); }
        .rp-file-chip i { color: var(--blue); flex-shrink: 0; }
        .rp-file-chip .file-size { color: var(--text-secondary); margin-left: auto; flex-shrink: 0; }
        .rp-file-chip .remove-file { background: none; border: none; color: #94a3b8; cursor: pointer; padding: 0; font-size: 0.7rem; margin-left: 0.4rem; flex-shrink: 0; }
        .rp-file-chip .remove-file:hover { color: #dc2626; }
        .rp-summary-panel { padding: 1.5rem 1.25rem; background: #f1f5f9; display: flex; flex-direction: column; gap: 1rem; overflow-y: auto; max-height: 68vh; }
        .rp-summary-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 5px; padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .rp-summary-title { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-secondary); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem; }
        .rp-summary-title i { color: var(--gold-dark); }
        .rp-summary-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; padding: 0.28rem 0; }
        .rp-summary-row .s-label { color: var(--text-secondary); }
        .rp-summary-row .s-val   { font-weight: 600; color: var(--text-primary); }
        .rp-summary-row.divider  { border-top: 1px solid #f1f5f9; margin-top: 0.25rem; padding-top: 0.55rem; }
        .rp-summary-row.total .s-label { color: var(--text-primary); font-weight: 700; font-size: 0.85rem; }
        .rp-summary-row.total .s-val   { color: var(--gold-dark); font-size: 1.05rem; font-weight: 800; }
        .rp-comm-val { color: #16a34a !important; }
        .variance-badge { font-size: 0.65rem; font-weight: 700; padding: 0.1rem 0.4rem; border-radius: 3px; }
        .variance-badge.over  { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .variance-badge.under { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }
        .variance-badge.exact { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .period-progress-wrap { margin-top: 0.25rem; }
        .period-progress-label { display: flex; justify-content: space-between; font-size: 0.65rem; color: var(--text-secondary); margin-bottom: 0.3rem; }
        .period-progress-bar-bg { background: #e2e8f0; border-radius: 4px; height: 6px; overflow: hidden; }
        .period-progress-bar-fill { height: 100%; border-radius: 4px; background: linear-gradient(90deg, var(--blue-dark), var(--blue-light)); transition: width 0.4s ease; }
        .rp-checklist { display: flex; flex-direction: column; gap: 0.35rem; }
        .rp-check-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.76rem; }
        .rp-check-item i { font-size: 0.75rem; flex-shrink: 0; }
        .rp-check-item.ok   i { color: #16a34a; }
        .rp-check-item.warn i { color: #d97706; }
        .rp-check-item.bad  i { color: #dc2626; }
        .rp-check-item.ok   span { color: var(--text-secondary); }
        .rp-check-item.warn span { color: #d97706; }
        .rp-check-item.bad  span { color: #dc2626; }

        /* ===== TOAST ===== */
        #toastContainer { position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.6rem; pointer-events: none; }
        .app-toast { display: flex; align-items: flex-start; gap: 0.85rem; background: #ffffff; border-radius: 12px; padding: 0.9rem 1.1rem; min-width: 300px; max-width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,0.16), 0 0 0 1px rgba(0,0,0,0.06); pointer-events: all; position: relative; overflow: hidden; animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards; }
        @keyframes toast-in { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }
        .app-toast::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }
        .app-toast-body { flex: 1; min-width: 0; }
        .app-toast-title { font-size: 0.82rem; font-weight: 700; color: #111827; margin-bottom: 0.2rem; }
        .app-toast-msg { font-size: 0.78rem; color: #6b7280; line-height: 1.4; word-break: break-word; }
        .app-toast-close { background: none; border: none; cursor: pointer; color: #9ca3af; font-size: 0.8rem; padding: 0; line-height: 1; flex-shrink: 0; transition: color .2s; }
        .app-toast-close:hover { color: #374151; }
        .app-toast-progress { position: absolute; bottom: 0; left: 0; height: 2px; border-radius: 0 0 0 12px; }
        .toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* ===== SKELETON ===== */
        @keyframes sk-shimmer { 0% { background-position: -1600px 0; } 100% { background-position: 1600px 0; } }
        .sk-shimmer { background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%); background-size: 1600px 100%; animation: sk-shimmer 1.6s ease-in-out infinite; border-radius: 4px; }
        #page-content { display: none; }

        /* ===== SPIN ===== */
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .spin { display: inline-block; animation: spin .7s linear infinite; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .admin-content { padding: 0; }
            .lm-body-content { padding: 0 1rem 1rem; }
            .lm-hero { height: 220px; }
            .lm-hero-title { font-size: 1.2rem; }
            .lm-hero-content { padding: 0 1rem 1rem; }
            .lm-hero-back { left: 1rem; }
            .lm-hero-type-badge { right: 1rem; }
            .lm-action-bar { padding: 0.75rem 1rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .lease-info-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
<?php
$active_page = 'admin_rental_payments.php';
include __DIR__ . '/admin_sidebar.php';
include __DIR__ . '/admin_navbar.php';
?>

<noscript><style>
    #sk-screen    { display: none !important; }
    #page-content { display: block !important; opacity: 1 !important; }
</style></noscript>

<!-- SKELETON SCREEN -->
<div id="sk-screen" class="admin-content" role="presentation" aria-hidden="true">
    <!-- Skeleton: Hero Banner -->
    <div style="height:280px;background:#1e293b;position:relative;overflow:hidden;margin-bottom:1.5rem;">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,transparent,#e8e3d0,#d4e0f7,transparent);"></div>
        <div style="position:absolute;bottom:1.5rem;left:2rem;">
            <div class="sk-shimmer" style="width:300px;height:24px;margin-bottom:8px;background:linear-gradient(90deg,rgba(255,255,255,0.05) 25%,rgba(255,255,255,0.1) 50%,rgba(255,255,255,0.05) 75%);background-size:1600px 100%;"></div>
            <div class="sk-shimmer" style="width:180px;height:14px;background:linear-gradient(90deg,rgba(255,255,255,0.05) 25%,rgba(255,255,255,0.1) 50%,rgba(255,255,255,0.05) 75%);background-size:1600px 100%;"></div>
        </div>
    </div>
    <div style="padding:0 2rem;">
        <!-- Skeleton: Action Bar -->
        <div style="background:#fff;border:1px solid rgba(37,99,235,0.1);border-radius:4px;padding:0.85rem 1.5rem;margin-bottom:1.5rem;display:flex;gap:0.6rem;">
            <div class="sk-shimmer" style="width:100px;height:28px;border-radius:3px;"></div>
            <div class="sk-shimmer" style="width:100px;height:28px;border-radius:3px;"></div>
            <div class="sk-shimmer" style="width:100px;height:28px;border-radius:3px;"></div>
        </div>
        <!-- Skeleton: KPI Grid -->
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem;">
            <?php for ($i = 0; $i < 5; $i++): ?>
            <div style="background:#fff;border:1px solid rgba(37,99,235,0.1);border-radius:4px;padding:1.15rem;">
                <div class="sk-shimmer" style="width:38px;height:38px;border-radius:4px;margin-bottom:0.65rem;"></div>
                <div class="sk-shimmer" style="width:70%;height:10px;margin-bottom:6px;"></div>
                <div class="sk-shimmer" style="width:45%;height:20px;"></div>
            </div>
            <?php endfor; ?>
        </div>
        <!-- Skeleton: 2-Column -->
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
            <div style="background:#fff;border:1px solid rgba(37,99,235,0.1);border-radius:4px;">
                <div style="background:#1e293b;padding:0.85rem 1.5rem;border-radius:4px 4px 0 0;"><div class="sk-shimmer" style="width:140px;height:12px;background:linear-gradient(90deg,rgba(255,255,255,0.05) 25%,rgba(255,255,255,0.1) 50%,rgba(255,255,255,0.05) 75%);background-size:1600px 100%;"></div></div>
                <div style="padding:1.5rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:0.75rem;">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:0.75rem 1rem;"><div class="sk-shimmer" style="width:60px;height:9px;margin-bottom:8px;"></div><div class="sk-shimmer" style="width:90px;height:15px;"></div></div>
                    <?php endfor; ?>
                </div>
            </div>
            <div>
                <div style="background:#fff;border:1px solid rgba(37,99,235,0.1);border-radius:4px;">
                    <div style="background:#1e293b;padding:0.85rem 1.5rem;border-radius:4px 4px 0 0;"><div class="sk-shimmer" style="width:120px;height:12px;background:linear-gradient(90deg,rgba(255,255,255,0.05) 25%,rgba(255,255,255,0.1) 50%,rgba(255,255,255,0.05) 75%);background-size:1600px 100%;"></div></div>
                    <div style="padding:1.5rem;"><div class="sk-shimmer" style="width:100%;height:10px;border-radius:6px;margin-bottom:1rem;"></div><div class="sk-shimmer" style="width:100%;height:12px;margin-bottom:0.5rem;"></div><div class="sk-shimmer" style="width:100%;height:12px;margin-bottom:0.5rem;"></div><div class="sk-shimmer" style="width:100%;height:12px;"></div></div>
                </div>
            </div>
        </div>
        <!-- Skeleton: Table -->
        <div style="background:#fff;border:1px solid rgba(37,99,235,0.1);border-radius:4px;overflow:hidden;">
            <div style="background:#1e293b;padding:0.85rem 1.5rem;"><div class="sk-shimmer" style="width:160px;height:12px;background:linear-gradient(90deg,rgba(255,255,255,0.05) 25%,rgba(255,255,255,0.1) 50%,rgba(255,255,255,0.05) 75%);background-size:1600px 100%;"></div></div>
            <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.85rem;">
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="display:flex;gap:1rem;align-items:center;">
                    <div class="sk-shimmer" style="width:26px;height:26px;border-radius:5px;"></div>
                    <div class="sk-shimmer" style="width:90px;height:13px;"></div>
                    <div class="sk-shimmer" style="width:130px;height:13px;"></div>
                    <div class="sk-shimmer" style="width:80px;height:13px;"></div>
                    <div class="sk-shimmer" style="width:65px;height:20px;"></div>
                    <div class="sk-shimmer" style="width:80px;height:13px;"></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
</div>

<!-- REAL PAGE CONTENT -->
<div id="page-content">
<div class="admin-content">

    <?php
    $statusClass = match($lease['lease_status']) {
        'Active'     => 'active',
        'Renewed'    => 'renewed',
        'Terminated' => 'terminated',
        'Expired'    => 'expired',
        default      => 'active'
    };
    ?>

    <!-- ===== Hero Banner ===== -->
    <div class="lm-hero">
        <div class="lm-hero-top-line"></div>
        <?php if (!empty($lease['thumb'])): ?>
        <img src="<?= htmlspecialchars($lease['thumb']) ?>" alt="<?= htmlspecialchars($lease['StreetAddress']) ?>" class="lm-hero-bg">
        <?php endif; ?>
        <div class="lm-hero-overlay"></div>
        <a href="admin_rental_payments.php" class="lm-hero-back">
            <i class="bi bi-arrow-left"></i> Back to Rental Payments
        </a>
        <span class="lm-hero-type-badge"><?= htmlspecialchars($lease['PropertyType'] ?? 'Property') ?></span>
        <div class="lm-hero-content">
            <div class="lm-hero-info">
                <h1 class="lm-hero-title"><?= htmlspecialchars($lease['StreetAddress']) ?></h1>
                <div class="lm-hero-addr"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($lease['City'] . ', ' . $lease['Province']) ?></div>
                <div class="lm-hero-badges">
                    <span class="lease-status-badge <?= $statusClass ?>">
                        <i class="bi bi-circle-fill" style="font-size:.35rem;"></i>
                        <?= htmlspecialchars($lease['lease_status']) ?>
                    </span>
                    <span class="lm-badge gold"><i class="bi bi-building"></i> <?= htmlspecialchars($lease['PropertyType'] ?? 'Property') ?></span>
                    <span class="lm-badge blue"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($agent_name) ?></span>
                    <span class="lm-badge teal"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($lease['tenant_name']) ?></span>
                </div>
            </div>
            <div class="lm-hero-price-block">
                <div class="lm-hero-rent">&#8369;<?= number_format($lease['monthly_rent'], 0) ?><span>/mo</span></div>
                <div class="lm-hero-rent-label">Monthly Rent</div>
            </div>
        </div>
    </div>

    <div class="lm-body-content">

    <!-- ===== Action Bar ===== -->
    <div class="lm-action-bar">
        <div class="lm-action-bar-left">
            <span class="lm-ab-badge gold"><i class="bi bi-building"></i> <?= htmlspecialchars($lease['PropertyType'] ?? 'Property') ?></span>
            <span class="lm-ab-badge blue"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($agent_name) ?></span>
            <span class="lm-ab-badge teal"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($lease['tenant_name']) ?></span>
        </div>
        <div class="lm-action-bar-right">
            <?php if ($is_active): ?>
                <button class="btn-action gold" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                    <i class="bi bi-plus-circle"></i> Record Payment
                </button>
                <button class="btn-action green" data-bs-toggle="modal" data-bs-target="#renewLeaseModal">
                    <i class="bi bi-arrow-repeat"></i> Renew
                </button>
                <button class="btn-action red" data-bs-toggle="modal" data-bs-target="#terminateLeaseModal">
                    <i class="bi bi-x-circle"></i> Terminate
                </button>
            <?php elseif ($lease['lease_status'] === 'Expired'): ?>
                <button class="btn-action green" data-bs-toggle="modal" data-bs-target="#renewLeaseModal">
                    <i class="bi bi-arrow-repeat"></i> Renew Lease
                </button>
                <button class="btn-action red" data-bs-toggle="modal" data-bs-target="#terminateLeaseModal">
                    <i class="bi bi-x-circle"></i> End Lease
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== KPI Grid ===== -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div class="kpi-label">Confirmed Payments</div>
            <div class="kpi-value"><?= $confirmed_count ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon amber"><i class="bi bi-clock-fill"></i></div>
            <div class="kpi-label">Pending Review</div>
            <div class="kpi-value"><?= $pending_count ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon red"><i class="bi bi-x-circle-fill"></i></div>
            <div class="kpi-label">Rejected</div>
            <div class="kpi-value"><?= $rejected_count ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon gold"><i class="bi bi-cash-stack"></i></div>
            <div class="kpi-label">Total Revenue</div>
            <div class="kpi-value kpi-currency">&#8369;<?= number_format($total_revenue, 0) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon blue"><i class="bi bi-coin"></i></div>
            <div class="kpi-label">Commission Earned</div>
            <div class="kpi-value kpi-currency">&#8369;<?= number_format($total_commission, 0) ?></div>
        </div>
    </div>

    <!-- ===== 2-Column: Lease Summary | Progress ===== -->
    <div class="lm-main-grid">

        <!-- LEFT: Lease Summary -->
        <div class="section-card" style="margin-bottom:0;">
            <div class="section-card-header">
                <h6><i class="bi bi-file-text-fill"></i> Lease Summary</h6>
                <div class="sc-header-right">
                    <span class="lease-status-badge <?= $statusClass ?>" style="background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.15);">
                        <i class="bi bi-circle-fill" style="font-size:.35rem;"></i>
                        <?= htmlspecialchars($lease['lease_status']) ?>
                    </span>
                </div>
            </div>
            <div class="section-card-body">
                <div class="lease-info-grid">
                    <div class="lease-info-item">
                        <div class="label"><i class="bi bi-person-fill"></i> Tenant</div>
                        <div class="value"><?= htmlspecialchars($lease['tenant_name']) ?></div>
                    </div>
                    <div class="lease-info-item">
                        <div class="label"><i class="bi bi-cash-coin"></i> Monthly Rent</div>
                        <div class="value gold-val">&#8369;<?= number_format($lease['monthly_rent'], 2) ?></div>
                    </div>
                    <div class="lease-info-item">
                        <div class="label"><i class="bi bi-shield-fill"></i> Security Deposit</div>
                        <div class="value">&#8369;<?= number_format($lease['security_deposit'], 2) ?></div>
                    </div>
                    <div class="lease-info-item">
                        <div class="label"><i class="bi bi-calendar-check"></i> Lease Start</div>
                        <div class="value"><?= date('M d, Y', strtotime($lease['lease_start_date'])) ?></div>
                    </div>
                    <div class="lease-info-item">
                        <div class="label"><i class="bi bi-calendar-x"></i> Lease End</div>
                        <div class="value"><?= date('M d, Y', strtotime($lease['lease_end_date'])) ?></div>
                    </div>
                    <div class="lease-info-item">
                        <div class="label"><i class="bi bi-hourglass-split"></i> Term</div>
                        <div class="value blue-val"><?= (int)$lease['lease_term_months'] ?> months</div>
                    </div>
                    <div class="lease-info-item">
                        <div class="label"><i class="bi bi-percent"></i> Commission Rate</div>
                        <div class="value green-val"><?= $lease['commission_rate'] ?>%</div>
                    </div>
                    <div class="lease-info-item">
                        <div class="label"><i class="bi bi-person-badge"></i> Agent</div>
                        <div class="value" style="font-size:.88rem;"><?= htmlspecialchars($agent_name) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Progress + Next Payment -->
        <div style="display:flex;flex-direction:column;gap:1rem;">

            <!-- Lease Progress Card -->
            <div class="section-card" style="margin-bottom:0;">
                <div class="section-card-header">
                    <h6><i class="bi bi-hourglass-split"></i> Lease Progress</h6>
                </div>
                <div class="section-card-body">
                    <?php
                    $start_ts     = strtotime($lease['lease_start_date']);
                    $end_ts       = strtotime($lease['lease_end_date']);
                    $now_ts       = time();
                    $total_days   = max(1, (int)(($end_ts - $start_ts) / 86400));
                    $elapsed_days = max(0, min($total_days, (int)(($now_ts - $start_ts) / 86400)));
                    $pct          = round($elapsed_days / $total_days * 100);
                    $remaining    = max(0, $total_days - $elapsed_days);
                    $prog_cls     = $pct >= 100 ? 'expired' : ($pct >= 80 ? 'warn' : '');
                    $pct_cls      = $pct >= 100 ? 'expired' : ($pct >= 80 ? 'warn' : 'blue');
                    $rem_cls      = $remaining <= 0 ? 'red' : ($remaining <= 30 ? 'gold' : 'green');
                    ?>
                    <div class="lm-progress-wrap">
                        <div class="lm-progress-label">
                            <span><?= date('M d, Y', $start_ts) ?></span>
                            <span><?= date('M d, Y', $end_ts) ?></span>
                        </div>
                        <div class="lm-progress-track">
                            <div class="lm-progress-fill <?= $prog_cls ?>" style="width:<?= $pct ?>%;"></div>
                        </div>
                        <div class="lm-progress-pct <?= $pct_cls ?>"><?= $pct ?>% elapsed &mdash; <?= $remaining ?> day<?= $remaining !== 1 ? 's' : '' ?> remaining</div>
                    </div>
                    <div>
                        <div class="lm-stat-row">
                            <span class="s-lbl"><i class="bi bi-calendar-range"></i> Total Term</span>
                            <span class="s-val"><?= $total_days ?> days</span>
                        </div>
                        <div class="lm-stat-row">
                            <span class="s-lbl"><i class="bi bi-check2-all"></i> Elapsed</span>
                            <span class="s-val blue"><?= $elapsed_days ?> days</span>
                        </div>
                        <div class="lm-stat-row">
                            <span class="s-lbl"><i class="bi bi-hourglass-bottom"></i> Remaining</span>
                            <span class="s-val <?= $rem_cls ?>"><?= $remaining ?> days</span>
                        </div>
                        <div class="lm-stat-row">
                            <span class="s-lbl"><i class="bi bi-receipt"></i> Total Payments</span>
                            <span class="s-val"><?= count($payments) ?></span>
                        </div>
                        <div class="lm-stat-row">
                            <span class="s-lbl"><i class="bi bi-coin"></i> Est. Commission</span>
                            <span class="s-val green">&#8369;<?= number_format($lease['monthly_rent'] * $lease['commission_rate'] / 100, 2) ?>/mo</span>
                        </div>
                    </div>
                    <div class="lm-revenue-strip">
                        <div>
                            <div class="rev-lbl"><i class="bi bi-cash-stack"></i> Total Revenue</div>
                            <div class="rev-val">&#8369;<?= number_format($total_revenue, 2) ?></div>
                        </div>
                        <i class="bi bi-coin rev-icon"></i>
                    </div>
                </div>
            </div>

            <!-- Next Payment Card -->
            <div class="section-card" style="margin-bottom:0;">
                <div class="section-card-header">
                    <h6><i class="bi bi-calendar3"></i> Next Payment Period</h6>
                </div>
                <div class="section-card-body">
                    <div class="lm-stat-row">
                        <span class="s-lbl"><i class="bi bi-calendar-event"></i> Suggested Start</span>
                        <span class="s-val blue"><?= date('M d, Y', strtotime($next_start)) ?></span>
                    </div>
                    <div class="lm-stat-row">
                        <span class="s-lbl"><i class="bi bi-calendar2-check"></i> Suggested End</span>
                        <span class="s-val blue"><?= date('M d, Y', strtotime($next_end)) ?></span>
                    </div>
                    <div class="lm-stat-row">
                        <span class="s-lbl"><i class="bi bi-cash-coin"></i> Expected Amount</span>
                        <span class="s-val gold">&#8369;<?= number_format($lease['monthly_rent'], 2) ?></span>
                    </div>
                    <?php if ($is_active): ?>
                    <div style="margin-top:1rem;">
                        <button class="btn-action gold" style="width:100%;justify-content:center;" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                            <i class="bi bi-plus-circle"></i> Record Payment
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- ===== Payment History ===== -->
    <div class="section-card">
        <div class="section-card-header">
            <h6><i class="bi bi-clock-history"></i> Payment History</h6>
            <div class="sc-header-right" style="gap:0.75rem;">
                <div class="pay-filter-tabs" id="payFilterTabs">
                    <button class="pay-filter-tab active" data-filter="all">All <span class="tab-count"><?= count($payments) ?></span></button>
                    <button class="pay-filter-tab" data-filter="confirmed">Confirmed <span class="tab-count"><?= $confirmed_count ?></span></button>
                    <button class="pay-filter-tab" data-filter="pending">Pending <span class="tab-count"><?= $pending_count ?></span></button>
                    <button class="pay-filter-tab" data-filter="rejected">Rejected <span class="tab-count"><?= $rejected_count ?></span></button>
                </div>
            </div>
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
                        <th>Admin Notes</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody id="payTableBody">
                    <?php $idx = count($payments); foreach ($payments as $p): ?>
                    <tr data-pay-status="<?= strtolower($p['status']) ?>">
                        <td><span class="pay-idx"><?= $idx-- ?></span></td>
                        <td style="font-weight:600;"><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                        <td class="muted">
                            <?= date('M d', strtotime($p['period_start'])) ?>
                            &ndash;
                            <?= date('M d, Y', strtotime($p['period_end'])) ?>
                        </td>
                        <td><span class="pay-amount-val">&#8369;<?= number_format($p['payment_amount'], 2) ?></span></td>
                        <td>
                            <?php $bc = strtolower($p['status']); ?>
                            <span class="pay-badge <?= $bc ?>">
                                <i class="bi bi-circle-fill" style="font-size:.3rem;"></i>
                                <?= htmlspecialchars($p['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($p['commission_amount'])): ?>
                                <span class="pay-comm-val"><span class="pay-comm-dot"></span>&#8369;<?= number_format($p['commission_amount'], 2) ?></span>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.78rem;color:var(--text-secondary);" title="<?= htmlspecialchars($p['admin_notes'] ?? '') ?>">
                            <?= !empty($p['admin_notes']) ? htmlspecialchars(mb_substr($p['admin_notes'], 0, 70)) : '<span style="opacity:.35;">&mdash;</span>' ?>
                        </td>
                        <td class="muted"><?= date('M d, Y', strtotime($p['submitted_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    </div><!-- /.lm-body-content -->

</div>
</div><!-- /#page-content -->

<!-- ============================
     MODALS (Admin Light Theme)
     ============================ -->

<!-- Record Payment Modal -->
<div class="modal fade modal-admin" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="overflow:hidden;">
            <form id="recordPaymentForm" enctype="multipart/form-data">
                <input type="hidden" name="rental_id" value="<?= $rental_id ?>">
                <div class="modal-header" style="padding:1.25rem 1.5rem;">
                    <h5 class="modal-title" id="recordPaymentModalLabel">
                        <i class="bi bi-cash-coin"></i> Record Rent Payment
                        <span style="font-size:0.75rem;font-weight:400;color:rgba(255,255,255,0.5);margin-left:0.5rem;"><?= htmlspecialchars($lease['StreetAddress']) ?></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="rp-modal-grid" style="max-height:68vh;">
                    <!-- LEFT: Form inputs -->
                    <div class="rp-form-panel">
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
                                2&times; &#8369;<?= number_format($lease['monthly_rent'] * 2, 0) ?>
                            </button>
                            <button type="button" class="quick-chip" data-amt="<?= $lease['monthly_rent'] * 3 ?>">
                                3&times; &#8369;<?= number_format($lease['monthly_rent'] * 3, 0) ?>
                            </button>
                        </div>

                        <p class="rp-section-label"><i class="bi bi-calendar3"></i> Payment Date</p>
                        <div class="mb-4">
                            <input type="date" class="form-control" id="rpPayDate" name="payment_date"
                                   required value="<?= date('Y-m-d') ?>">
                        </div>

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
                                <label class="form-label" style="font-size:0.75rem;">End <span class="req">*</span> <span id="rpAutoFillNote" style="font-size:0.65rem;color:var(--blue);opacity:0.8;">auto-filled</span></label>
                                <input type="date" class="form-control" id="rpPeriodEnd" name="period_end"
                                       required value="<?= htmlspecialchars($next_end) ?>">
                            </div>
                        </div>

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

                        <p class="rp-section-label"><i class="bi bi-chat-left-text"></i> Notes <span style="font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0;">(optional)</span></p>
                        <textarea class="form-control" name="additional_notes" id="rpNotes" rows="2"
                                  maxlength="2000" placeholder="e.g. GCash ref #123456, bank transfer..."></textarea>
                    </div>

                    <!-- RIGHT: Live summary -->
                    <div class="rp-summary-panel">
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
                                <span class="s-val" id="srpDays">&mdash;</span>
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
                    </div>
                </div>

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
<div class="modal fade modal-admin" id="renewLeaseModal" tabindex="-1" aria-labelledby="renewLeaseModalLabel" aria-hidden="true">
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
<div class="modal fade modal-admin" id="terminateLeaseModal" tabindex="-1" aria-labelledby="terminateLeaseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="terminateLeaseForm">
                <input type="hidden" name="rental_id" value="<?= $rental_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="terminateLeaseModalLabel">
                        <i class="bi bi-x-circle" style="color:#f87171 !important;"></i>
                        <span style="color:#f87171;">Terminate Lease / Tenant Move-Out</span>
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
                    <p style="font-size:0.82rem;color:var(--text-secondary);margin:0;">Pending rent payments (if any) will remain as-is for admin review.</p>
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

<?php include 'logout_modal.php'; ?>
<div id="toastContainer"></div>

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
<script>
// ===== PAYMENT FILTER TABS =====
(function () {
    var tabsWrap = document.getElementById('payFilterTabs');
    var tbody    = document.getElementById('payTableBody');
    if (!tabsWrap || !tbody) return;

    tabsWrap.addEventListener('click', function (e) {
        var btn = e.target.closest('.pay-filter-tab');
        if (!btn) return;
        tabsWrap.querySelectorAll('.pay-filter-tab').forEach(function (t) { t.classList.remove('active'); });
        btn.classList.add('active');
        var filter = btn.dataset.filter;
        tbody.querySelectorAll('tr[data-pay-status]').forEach(function (row) {
            if (filter === 'all' || row.dataset.payStatus === filter) {
                row.classList.remove('pay-hidden');
            } else {
                row.classList.add('pay-hidden');
            }
        });
    });
}());

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
    fetch('agent_pages/record_rental_payment.php', { method: 'POST', body: new FormData(this) })
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

    function updateSummary() {
        var amount = parseFloat(rpAmount.value) || 0;
        var comm   = amount * COMM_RATE / 100;
        var diff   = amount - MONTHLY_RENT;

        srpAmount.textContent = fmt(amount);
        srpComm.textContent   = fmt(comm);
        srpTotal.textContent  = fmt(amount);

        var badge = '';
        if (Math.abs(diff) < 0.01) {
            badge = '<span class="variance-badge exact">Exact</span>';
        } else if (diff > 0) {
            badge = '<span class="variance-badge over">+' + fmt(diff) + ' over</span>';
        } else {
            badge = '<span class="variance-badge under">' + fmt(diff) + ' short</span>';
        }
        srpVariance.innerHTML = badge;

        var ps = rpPeriodStart.value;
        var pe = rpPeriodEnd.value;
        srpPeriodStart.textContent = ps ? fmtDate(ps) : '—';
        srpPeriodEnd.textContent   = pe ? fmtDate(pe) : '—';

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

        setCheck(chkAmount, amount > 0 ? 'ok' : 'bad', amount > 0 ? 'Payment amount set' : 'Enter payment amount');
        setCheck(chkDate,   rpPayDate.value ? 'ok' : 'bad', rpPayDate.value ? 'Payment date set' : 'Set payment date');
        setCheck(chkPeriod, periodOk ? 'ok' : 'bad', periodOk ? 'Period dates valid' : 'Check period dates');
        var fileOk = selectedFiles.length > 0;
        setCheck(chkDocs,   fileOk ? 'ok' : 'bad', fileOk ? selectedFiles.length + ' document(s) attached' : 'No documents uploaded');
    }

    document.querySelectorAll('#rpAmountChips .quick-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            rpAmount.value = parseFloat(this.dataset.amt).toFixed(2);
            document.querySelectorAll('#rpAmountChips .quick-chip').forEach(function(c) { c.classList.remove('active'); });
            this.classList.add('active');
            updateSummary();
        });
    });

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
        var dt = new DataTransfer();
        selectedFiles.forEach(function(f) { dt.items.add(f); });
        rpFileInput.files = dt.files;
    }

    document.getElementById('recordPaymentModal').addEventListener('show.bs.modal', function() {
        selectedFiles = [];
        rpFileList.innerHTML = '';
        syncFileInput();
        updateSummary();
    });

    updateSummary();
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
    fetch('agent_pages/renew_lease.php', { method: 'POST', body: new FormData(this) })
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
    fetch('agent_pages/terminate_lease.php', { method: 'POST', body: new FormData(this) })
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

<!-- SKELETON HYDRATION -->
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
</body>
</html>
