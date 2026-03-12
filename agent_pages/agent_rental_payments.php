<?php
session_start();
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../config/session_timeout.php';
require_once __DIR__ . '/../config/paths.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header('Location: ../login.php');
    exit;
}

$agent_account_id = (int)$_SESSION['account_id'];
$agent_username = $_SESSION['username'];

// --- Fetch Agent Info for navbar ---
$agent_info_sql = "
    SELECT a.first_name, a.last_name, a.username, ai.profile_picture_url
    FROM accounts a 
    JOIN agent_information ai ON a.account_id = ai.account_id
    WHERE a.account_id = ?";
$stmt_agent = $conn->prepare($agent_info_sql);
$stmt_agent->bind_param("i", $agent_account_id);
$stmt_agent->execute();
$agent = $stmt_agent->get_result()->fetch_assoc();
$stmt_agent->close();

// --- Fetch rental payments for this agent ---
$sql = "
    SELECT rp.*,
           fr.tenant_name, fr.monthly_rent AS lease_rent, fr.commission_rate,
           fr.lease_start_date, fr.lease_end_date, fr.lease_status,
           p.property_ID, p.StreetAddress, p.City, p.Province, p.PropertyType,
           rc.commission_amount, rc.status AS commission_status,
           (SELECT pi.PhotoURL FROM property_images pi WHERE pi.property_ID = p.property_ID ORDER BY pi.SortOrder ASC LIMIT 1) AS property_image
    FROM rental_payments rp
    JOIN finalized_rentals fr ON rp.rental_id = fr.rental_id
    JOIN property p ON fr.property_id = p.property_ID
    LEFT JOIN rental_commissions rc ON rp.payment_id = rc.payment_id
    WHERE rp.agent_id = ?
    ORDER BY rp.submitted_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $agent_account_id);
$stmt->execute();
$result = $stmt->get_result();
$payments = [];
while ($row = $result->fetch_assoc()) $payments[] = $row;
$stmt->close();

$pending   = array_filter($payments, fn($p) => $p['status'] === 'Pending');
$confirmed = array_filter($payments, fn($p) => $p['status'] === 'Confirmed');
$rejected  = array_filter($payments, fn($p) => $p['status'] === 'Rejected');

$total_confirmed_revenue = array_sum(array_map(fn($p) => (float)$p['payment_amount'], $confirmed));
$total_commission_earned = array_sum(array_map(fn($p) => (float)($p['commission_amount'] ?? 0), $confirmed));

// --- Fetch active rentals for quick links ---
$rentals_sql = "
    SELECT fr.rental_id, fr.property_id, fr.tenant_name, fr.lease_status,
           p.StreetAddress, p.City
    FROM finalized_rentals fr
    JOIN property p ON fr.property_id = p.property_ID
    WHERE fr.agent_id = ? AND fr.lease_status IN ('Active', 'Renewed')
    ORDER BY fr.finalized_at DESC
";
$stmt_r = $conn->prepare($rentals_sql);
$stmt_r->bind_param('i', $agent_account_id);
$stmt_r->execute();
$active_rentals = $stmt_r->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_r->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/Logo.png" type="image/png">
    <link rel="shortcut icon" href="../images/Logo.png" type="image/png">
    <title>Rental Payments - HomeEstate Realty</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    <style>
        :root {
            --gold: #d4af37; --gold-light: #f4d03f; --gold-dark: #b8941f;
            --blue: #2563eb; --blue-light: #3b82f6; --blue-dark: #1e40af;
            --black: #0a0a0a; --black-light: #111111; --black-lighter: #1a1a1a; --black-border: #1f1f1f;
            --white: #ffffff;
            --gray-400: #9ca4ab; --gray-500: #7a8a99; --gray-600: #5d6d7d; --gray-900: #1a1f24;
            --card-bg: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            --card-border: rgba(37, 99, 235, 0.15);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--black); color: var(--white); line-height: 1.6; overflow-x: hidden; }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(26, 26, 26, 0.4); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--gold), var(--gold-dark));
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--gold-light), var(--gold));
        }

        /* ===== MAIN CONTENT ===== */
        .rental-content { max-width: 1400px; margin: 0 auto; padding: 2rem 2rem 4rem; }

        /* ===== PAGE HEADER ===== */
        .page-header { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
        .page-header h1 { font-size: 1.6rem; font-weight: 800; color: var(--white); margin-bottom: 0.2rem; }
        .page-header .subtitle { font-size: 0.9rem; color: var(--gray-400); font-weight: 400; }

        /* ===== KPI CARDS ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 0.85rem; transition: all 0.2s; }
        .kpi-card:hover { transform: translateY(-2px); border-color: rgba(37,99,235,0.3); box-shadow: 0 4px 16px rgba(37,99,235,0.08); }
        .kpi-icon { width: 44px; height: 44px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .kpi-icon.amber { background: linear-gradient(135deg, rgba(245,158,11,0.1), rgba(245,158,11,0.2)); color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }
        .kpi-icon.green { background: linear-gradient(135deg, rgba(34,197,94,0.1), rgba(34,197,94,0.2)); color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }
        .kpi-icon.red { background: linear-gradient(135deg, rgba(239,68,68,0.1), rgba(239,68,68,0.2)); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
        .kpi-icon.blue { background: linear-gradient(135deg, rgba(37,99,235,0.1), rgba(37,99,235,0.2)); color: #3b82f6; border: 1px solid rgba(37,99,235,0.2); }
        .kpi-icon.gold { background: linear-gradient(135deg, rgba(212,175,55,0.1), rgba(212,175,55,0.2)); color: var(--gold); border: 1px solid rgba(212,175,55,0.2); }
        .kpi-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: var(--gray-400); margin-bottom: 0.1rem; }
        .kpi-value { font-size: 1.35rem; font-weight: 800; color: var(--white); }

        /* ===== ACTIVE RENTALS BAR ===== */
        .rentals-bar { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; padding: 0.85rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; position: relative; overflow: hidden; }
        .rentals-bar::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .rentals-bar-label { font-size: 0.78rem; font-weight: 700; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 0.4rem; flex-shrink: 0; }
        .rentals-bar-label i { color: var(--gold); }
        .rental-link { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.7rem; background: rgba(37,99,235,0.08); border: 1px solid rgba(37,99,235,0.2); border-radius: 3px; font-size: 0.75rem; font-weight: 600; color: var(--blue-light); text-decoration: none; transition: all 0.2s; white-space: nowrap; }
        .rental-link:hover { background: rgba(37,99,235,0.15); color: #60a5fa; border-color: rgba(37,99,235,0.35); }
        .rental-link i { font-size: 0.7rem; }

        /* ===== SEARCH & ACTION BAR ===== */
        .action-bar { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; padding: 0.85rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .action-search-wrap { position: relative; flex: 1; min-width: 200px; }
        .action-search-wrap input { width: 100%; padding: 0.5rem 1rem 0.5rem 2.35rem; border: 1px solid rgba(255,255,255,0.08); border-radius: 4px; font-size: 0.85rem; color: var(--white); background: rgba(255,255,255,0.04); transition: all 0.2s; }
        .action-search-wrap input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.12); outline: none; background: rgba(255,255,255,0.06); }
        .action-search-wrap input::placeholder { color: var(--gray-500); }
        .action-search-wrap .search-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--gray-500); font-size: 0.85rem; pointer-events: none; }

        /* ===== STATUS TABS ===== */
        .payment-tabs { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; position: relative; }
        .payment-tabs::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .payment-tabs .nav-tabs { border: none; padding: 0 1rem; margin: 0; }
        .payment-tabs .nav-item { margin: 0; }
        .payment-tabs .nav-link { border: none; border-radius: 0; padding: 0.9rem 1.15rem; font-size: 0.82rem; font-weight: 600; color: var(--gray-400); background: transparent; transition: all 0.2s; display: flex; align-items: center; gap: 0.5rem; border-bottom: 2px solid transparent; }
        .payment-tabs .nav-link:hover { color: var(--white); background: rgba(37,99,235,0.04); }
        .payment-tabs .nav-link.active { color: var(--gold); border-bottom-color: var(--gold); background: rgba(212,175,55,0.05); }
        .tab-badge { font-size: 0.68rem; padding: 0.12rem 0.45rem; border-radius: 8px; font-weight: 700; }
        .badge-all       { background: rgba(212,175,55,0.1); color: var(--gold); border: 1px solid rgba(212,175,55,0.2); }
        .badge-pending   { background: rgba(245,158,11,0.1); color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }
        .badge-confirmed { background: rgba(34,197,94,0.1); color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }
        .badge-rejected  { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }

        .tab-content { padding: 1.25rem; }

        /* ===== PAYMENT CARDS GRID ===== */
        .payments-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }

        /* ===== PAYMENT CARD ===== */
        .payment-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 4px; overflow: hidden; transition: all 0.3s; height: 100%; display: flex; flex-direction: column; position: relative; }
        .payment-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); opacity: 0; transition: opacity 0.3s; z-index: 5; }
        .payment-card:hover { border-color: rgba(37,99,235,0.3); box-shadow: 0 8px 32px rgba(37,99,235,0.1); transform: translateY(-3px); }
        .payment-card:hover::before { opacity: 1; }

        .card-img-wrap { position: relative; height: 170px; background: var(--black-lighter); overflow: hidden; }
        .card-img-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s; }
        .payment-card:hover .card-img-wrap img { transform: scale(1.05); }
        .card-img-wrap .img-overlay { position: absolute; bottom: 0; left: 0; right: 0; height: 60%; background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, transparent 100%); pointer-events: none; }

        .card-img-wrap .type-badge { position: absolute; bottom: 10px; left: 12px; padding: 0.18rem 0.55rem; border-radius: 2px; font-size: 0.63rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 3; background: rgba(0,0,0,0.7); color: #e2e8f0; backdrop-filter: blur(4px); display: inline-flex; align-items: center; gap: 0.3rem; }
        .card-img-wrap .status-badge { position: absolute; top: 10px; right: 10px; display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.55rem; border-radius: 2px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 3; }
        .status-badge.pending   { background: rgba(245,158,11,0.9); color: #fff; }
        .status-badge.confirmed { background: rgba(34,197,94,0.9);  color: #fff; }
        .status-badge.rejected  { background: rgba(239,68,68,0.9);  color: #fff; }

        .card-img-wrap .price-overlay { position: absolute; bottom: 10px; right: 12px; z-index: 3; }
        .card-img-wrap .price-overlay .price { font-size: 1.25rem; font-weight: 800; background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.6)); }

        .payment-card .card-body-content { padding: 0.9rem 1.15rem; flex: 1; display: flex; flex-direction: column; }
        .payment-card .prop-address { font-size: 0.9rem; font-weight: 700; color: var(--white); margin: 0 0 0.15rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .payment-card .prop-location { font-size: 0.78rem; color: var(--gray-400); display: flex; align-items: center; gap: 0.3rem; margin-bottom: 0.65rem; }
        .payment-card .prop-location i { color: var(--blue-light); font-size: 0.72rem; }

        .payment-meta-row { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.65rem; }
        .payment-meta-item { display: inline-flex; align-items: center; gap: 0.25rem; background: rgba(255,255,255,0.04); padding: 0.18rem 0.5rem; border-radius: 2px; border: 1px solid rgba(255,255,255,0.06); font-size: 0.72rem; font-weight: 500; color: var(--gray-400); }
        .payment-meta-item i { font-size: 0.68rem; }
        .payment-meta-item.tenant-meta i { color: var(--gold); }
        .payment-meta-item.date-meta i { color: var(--gold); }
        .payment-meta-item.comm-meta i { color: #22c55e; }
        .payment-meta-item.period-meta i { color: var(--blue-light); }
        .payment-meta-item.lease-meta i { color: #8b5cf6; }

        .payment-card .card-footer-section { margin-top: auto; padding-top: 0.65rem; border-top: 1px solid rgba(255,255,255,0.06); display: flex; gap: 0.5rem; }
        .payment-card .btn-manage { display: flex; align-items: center; justify-content: center; gap: 0.4rem; flex: 1; background: linear-gradient(135deg, var(--blue-dark), var(--blue)); color: #fff; border: none; padding: 0.5rem; font-size: 0.78rem; font-weight: 700; border-radius: 3px; cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em; transition: all 0.3s; text-decoration: none; }
        .payment-card .btn-manage:hover { box-shadow: 0 4px 16px rgba(37,99,235,0.3); transform: translateY(-1px); }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 4rem 2rem; }
        .empty-state i { font-size: 3rem; color: var(--gray-500); opacity: 0.3; margin-bottom: 0.75rem; display: block; }
        .empty-state h4 { font-size: 1.05rem; font-weight: 700; color: var(--white); margin-bottom: 0.25rem; }
        .empty-state p { color: var(--gray-400); margin: 0; }

        /* ===== TOAST ===== */
        #toastContainer { position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.6rem; pointer-events: none; }
        .app-toast { display: flex; align-items: flex-start; gap: 0.85rem; background: var(--black-lighter); border: 1px solid var(--card-border); border-radius: 10px; padding: 0.9rem 1.1rem; min-width: 300px; max-width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,0.25); pointer-events: all; position: relative; overflow: hidden; animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards; }
        @keyframes toast-in { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); } }
        .app-toast::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
        .app-toast.toast-success::before { background: linear-gradient(180deg, var(--gold), var(--gold-dark)); }
        .app-toast.toast-error::before { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before { background: linear-gradient(180deg, var(--blue), var(--blue-dark)); }
        .app-toast-icon { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex-shrink: 0; }
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.12); color: var(--gold); }
        .toast-error .app-toast-icon { background: rgba(239,68,68,0.1); color: #ef4444; }
        .toast-info .app-toast-icon { background: rgba(37,99,235,0.1); color: var(--blue); }
        .app-toast-body { flex: 1; min-width: 0; }
        .app-toast-title { font-size: 0.82rem; font-weight: 700; color: var(--white); margin-bottom: 0.15rem; }
        .app-toast-msg { font-size: 0.78rem; color: var(--gray-400); line-height: 1.4; }
        .app-toast-close { background: none; border: none; cursor: pointer; color: var(--gray-500); font-size: 0.8rem; padding: 0; line-height: 1; flex-shrink: 0; transition: color .2s; }
        .app-toast-close:hover { color: var(--white); }
        .app-toast-progress { position: absolute; bottom: 0; left: 0; height: 2px; border-radius: 0 0 0 10px; }
        .toast-success .app-toast-progress { background: linear-gradient(90deg, var(--gold), var(--gold-dark)); }
        .toast-error .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info .app-toast-progress { background: linear-gradient(90deg, var(--blue), var(--blue-dark)); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .rental-content { padding: 1rem; }
            .page-header { padding: 1.5rem; }
            .page-header h1 { font-size: 1.3rem; }
            .payments-grid { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 576px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }

        /* ===== SKELETON ===== */
        @keyframes sk-shimmer { 0% { background-position: -1600px 0; } 100% { background-position: 1600px 0; } }
        .sk-shimmer { background: linear-gradient(90deg, #1a1a1a 25%, #222 50%, #1a1a1a 75%); background-size: 1600px 100%; animation: sk-shimmer 1.6s ease-in-out infinite; border-radius: 4px; }
        #page-content { display: none; }
    </style>
</head>
<body>
    <?php
    $active_page = 'agent_rental_payments.php';
    include 'agent_navbar.php';
    ?>

    <!-- Skeleton Screen -->
    <div id="sk-screen" role="presentation" aria-hidden="true">
        <div class="rental-content">
            <div style="background:rgba(26,26,26,0.8);border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:2rem 2.5rem;margin-bottom:1.5rem;">
                <div class="sk-shimmer" style="width:200px;height:22px;margin-bottom:10px;"></div>
                <div class="sk-shimmer" style="width:340px;height:13px;"></div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem;">
                <?php for ($i = 0; $i < 5; $i++): ?>
                <div style="background:rgba(26,26,26,0.8);border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:1.1rem;display:flex;align-items:center;gap:0.85rem;">
                    <div class="sk-shimmer" style="width:44px;height:44px;border-radius:4px;flex-shrink:0;"></div>
                    <div style="flex:1;"><div class="sk-shimmer" style="width:65%;height:10px;margin-bottom:6px;"></div><div class="sk-shimmer" style="width:45%;height:18px;"></div></div>
                </div>
                <?php endfor; ?>
            </div>
            <div style="background:rgba(26,26,26,0.8);border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:1rem;margin-bottom:1.5rem;">
                <div class="sk-shimmer" style="width:100%;height:36px;border-radius:4px;"></div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.25rem;">
                <?php for ($i = 0; $i < 3; $i++): ?>
                <div style="background:rgba(26,26,26,0.8);border:1px solid rgba(37,99,235,0.15);border-radius:4px;overflow:hidden;">
                    <div class="sk-shimmer" style="height:170px;"></div>
                    <div style="padding:0.9rem 1.15rem;">
                        <div class="sk-shimmer" style="width:80%;height:16px;margin-bottom:8px;"></div>
                        <div class="sk-shimmer" style="width:50%;height:12px;margin-bottom:12px;"></div>
                        <div style="display:flex;gap:6px;margin-bottom:12px;">
                            <div class="sk-shimmer" style="width:80px;height:10px;border-radius:3px;"></div>
                            <div class="sk-shimmer" style="width:90px;height:10px;border-radius:3px;"></div>
                        </div>
                        <div class="sk-shimmer" style="width:100%;height:34px;border-radius:3px;"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- Real Page Content -->
    <div id="page-content">
    <div class="rental-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1><i class="bi bi-receipt me-2" style="font-size:1.4rem;"></i>Rental Payments</h1>
                    <p class="subtitle">Track your rent payment submissions and commission earnings</p>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-layers-fill"></i></div>
                <div><div class="kpi-label">Total Payments</div><div class="kpi-value"><?= count($payments) ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="bi bi-clock-fill"></i></div>
                <div><div class="kpi-label">Pending</div><div class="kpi-value"><?= count($pending) ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-check-circle-fill"></i></div>
                <div><div class="kpi-label">Confirmed</div><div class="kpi-value"><?= count($confirmed) ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="bi bi-x-circle-fill"></i></div>
                <div><div class="kpi-label">Rejected</div><div class="kpi-value"><?= count($rejected) ?></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="bi bi-coin"></i></div>
                <div><div class="kpi-label">Commission Earned</div><div class="kpi-value">&#8369;<?= number_format($total_commission_earned, 0) ?></div></div>
            </div>
        </div>

        <!-- Active Rentals Quick Links -->
        <?php if (!empty($active_rentals)): ?>
        <div class="rentals-bar">
            <span class="rentals-bar-label"><i class="bi bi-house-check-fill"></i> Active Leases:</span>
            <?php foreach ($active_rentals as $ar): ?>
                <a href="rental_payments.php?property_id=<?= $ar['property_id'] ?>" class="rental-link">
                    <i class="bi bi-key-fill"></i> <?= htmlspecialchars($ar['StreetAddress']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Search Bar -->
        <div class="action-bar">
            <div class="action-search-wrap">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="quickSearchInput" placeholder="Search by property, tenant, or payment period..." autocomplete="off">
            </div>
        </div>

        <!-- Status Tabs -->
        <div class="payment-tabs">
            <ul class="nav nav-tabs" id="paymentStatusTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-all" type="button" role="tab">
                        <i class="bi bi-layers"></i> All <span class="tab-badge badge-all"><?= count($payments) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pending" type="button" role="tab">
                        <i class="bi bi-clock-history"></i> Pending <span class="tab-badge badge-pending"><?= count($pending) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-confirmed" type="button" role="tab">
                        <i class="bi bi-check-circle"></i> Confirmed <span class="tab-badge badge-confirmed"><?= count($confirmed) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rejected" type="button" role="tab">
                        <i class="bi bi-x-circle"></i> Rejected <span class="tab-badge badge-rejected"><?= count($rejected) ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- All Tab -->
                <div class="tab-pane fade show active" id="tab-all" role="tabpanel">
                    <?php if (empty($payments)): ?>
                        <div class="empty-state">
                            <i class="bi bi-cash-stack"></i>
                            <h4>No Rental Payments Yet</h4>
                            <p>Payment records will appear here once you record rent payments for your leased properties.</p>
                        </div>
                    <?php else: ?>
                        <div class="payments-grid">
                            <?php foreach ($payments as $p): ?>
                                <?php include __DIR__ . '/agent_rental_payment_card.php'; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pending Tab -->
                <div class="tab-pane fade" id="tab-pending" role="tabpanel">
                    <?php if (empty($pending)): ?>
                        <div class="empty-state">
                            <i class="bi bi-clock"></i>
                            <h4>No Pending Payments</h4>
                            <p>All your payment submissions have been reviewed.</p>
                        </div>
                    <?php else: ?>
                        <div class="payments-grid">
                            <?php foreach ($pending as $p): ?>
                                <?php include __DIR__ . '/agent_rental_payment_card.php'; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Confirmed Tab -->
                <div class="tab-pane fade" id="tab-confirmed" role="tabpanel">
                    <?php if (empty($confirmed)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle"></i>
                            <h4>No Confirmed Payments</h4>
                            <p>Confirmed payment records will appear here after admin review.</p>
                        </div>
                    <?php else: ?>
                        <div class="payments-grid">
                            <?php foreach ($confirmed as $p): ?>
                                <?php include __DIR__ . '/agent_rental_payment_card.php'; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Rejected Tab -->
                <div class="tab-pane fade" id="tab-rejected" role="tabpanel">
                    <?php if (empty($rejected)): ?>
                        <div class="empty-state">
                            <i class="bi bi-x-circle"></i>
                            <h4>No Rejected Payments</h4>
                            <p>No payments have been rejected.</p>
                        </div>
                    <?php else: ?>
                        <div class="payments-grid">
                            <?php foreach ($rejected as $p): ?>
                                <?php include __DIR__ . '/agent_rental_payment_card.php'; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
    </div><!-- /#page-content -->

    <?php include 'logout_agent_modal.php'; ?>
    <div id="toastContainer"></div>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
    /* ===== TOAST ===== */
    function showToast(type, title, message, duration) {
        duration = duration || 4500;
        var icons = { success:'bi-check-circle-fill', error:'bi-x-octagon-fill', info:'bi-info-circle-fill' };
        var c = document.getElementById('toastContainer');
        var t = document.createElement('div');
        t.className = 'app-toast toast-' + type;
        t.innerHTML =
            '<div class="app-toast-icon"><i class="bi ' + (icons[type]||icons.info) + '"></i></div>' +
            '<div class="app-toast-body"><div class="app-toast-title">' + title + '</div><div class="app-toast-msg">' + message + '</div></div>' +
            '<button class="app-toast-close" onclick="this.parentElement.classList.add(\'toast-out\');setTimeout(()=>this.parentElement.remove(),300)">&times;</button>' +
            '<div class="app-toast-progress" style="animation: toast-progress ' + duration + 'ms linear forwards;"></div>';
        c.appendChild(t);
        setTimeout(function() { t.classList.add('toast-out'); setTimeout(function() { t.remove(); }, 300); }, duration);
    }

    /* ===== SEARCH FILTER ===== */
    var searchInput = document.getElementById('quickSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('.tab-pane.active .payment-card').forEach(function(card) {
                var text = card.textContent.toLowerCase();
                card.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    /* ===== Tab change clears search filter display ===== */
    document.querySelectorAll('#paymentStatusTabs .nav-link').forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function() {
            if (searchInput && searchInput.value.trim()) {
                searchInput.dispatchEvent(new Event('input'));
            }
        });
    });

    /* ===== SKELETON HYDRATION ===== */
    (function() {
        var MIN = 400, start = Date.now();
        function hydrate() {
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');
            if (!pc) return;
            if (!sk) { pc.style.cssText = 'display:block;opacity:1;'; return; }
            pc.style.display = 'block';
            pc.style.opacity = '0';
            requestAnimationFrame(function() {
                sk.style.transition = 'opacity 0.35s ease';
                sk.style.opacity = '0';
                pc.style.transition = 'opacity 0.42s ease 0.1s';
                requestAnimationFrame(function() { pc.style.opacity = '1'; });
            });
            setTimeout(function() {
                if (sk && sk.parentNode) sk.parentNode.removeChild(sk);
                pc.style.transition = '';
                pc.style.opacity = '';
            }, 520);
        }
        function schedule() {
            var elapsed = Date.now() - start;
            var remaining = Math.max(0, MIN - elapsed);
            remaining > 0 ? setTimeout(hydrate, remaining) : hydrate();
        }
        document.readyState === 'complete' ? schedule() : window.addEventListener('load', schedule);
    })();
    </script>
</body>
</html>
