<?php
session_start();
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../config/paths.php';

// Agent-only access
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
$agent_info = $stmt_agent->get_result()->fetch_assoc();
$stmt_agent->close();

// --- Fetch commissions for this agent ---
$sql = "
    SELECT 
        ac.commission_id, ac.sale_id, ac.agent_id,
        ac.commission_amount, ac.commission_percentage, ac.status,
        ac.calculated_at, ac.paid_at, ac.payment_reference, ac.created_at,
        fs.property_id, fs.final_sale_price, fs.sale_date,
        fs.buyer_name, fs.buyer_email,
        fs.additional_notes, fs.finalized_at,
        p.StreetAddress, p.City, p.Province, p.PropertyType,
        p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice
    FROM agent_commissions ac
    JOIN finalized_sales fs ON fs.sale_id = ac.sale_id
    LEFT JOIN property p ON p.property_ID = fs.property_id
    WHERE ac.agent_id = ?
    ORDER BY ac.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $agent_account_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
$totalEarnings = 0.0;
$totalPaid = 0.0;
$totalPending = 0.0;
$totalCalculated = 0.0;
$totalSalesVolume = 0.0;
$paidCount = 0;
$pendingCount = 0;
$calculatedCount = 0;
$cancelledCount = 0;

while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
    $amount = (float)$r['commission_amount'];
    $totalEarnings += $amount;
    $totalSalesVolume += (float)$r['final_sale_price'];
    
    switch (strtolower($r['status'])) {
        case 'paid':
            $totalPaid += $amount;
            $paidCount++;
            break;
        case 'pending':
            $totalPending += $amount;
            $pendingCount++;
            break;
        case 'calculated':
            $totalCalculated += $amount;
            $calculatedCount++;
            break;
        case 'cancelled':
            $cancelledCount++;
            break;
    }
}
$stmt->close();

// Calculate average commission rate
$avgRate = count($rows) > 0 ? array_sum(array_column($rows, 'commission_percentage')) / count($rows) : 0;

// Get monthly earnings for chart (last 12 months)
$monthlyData = [];
for ($i = 11; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-$i months"));
    $monthlyData[$monthKey] = ['earned' => 0, 'paid' => 0, 'label' => date('M Y', strtotime("-$i months")), 'short' => date('M', strtotime("-$i months"))];
}
foreach ($rows as $r) {
    $dateRef = $r['calculated_at'] ?? $r['sale_date'] ?? $r['created_at'];
    if ($dateRef) {
        $month = date('Y-m', strtotime($dateRef));
        if (isset($monthlyData[$month])) {
            $monthlyData[$month]['earned'] += (float)$r['commission_amount'];
            if (strtolower($r['status']) === 'paid') {
                $monthlyData[$month]['paid'] += (float)$r['commission_amount'];
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commissions - HomeEstate Realty</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">

    <style>
        /* ===== DARK THEME VARIABLES ===== */
        :root {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --black: #0a0a0a;
            --black-light: #111111;
            --black-lighter: #1a1a1a;
            --black-border: #1f1f1f;
            --white: #ffffff;
            --gray-50: #f8f9fa;
            --gray-100: #e8e9eb;
            --gray-200: #d1d4d7;
            --gray-300: #b8bec4;
            --gray-400: #9ca4ab;
            --gray-500: #7a8a99;
            --gray-600: #5d6d7d;
            --gray-700: #3f4b56;
            --gray-800: #2a3138;
            --gray-900: #1a1f24;
            --card-bg: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            --card-border: rgba(37, 99, 235, 0.15);
            --card-hover-border: rgba(37, 99, 235, 0.35);
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--black);
            color: var(--white);
            line-height: 1.6;
            overflow-x: hidden;
        }

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
        .commission-content {
            padding: 2rem;
            max-width: 1440px;
            margin: 0 auto;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.04) 0%, transparent 50%);
            pointer-events: none;
        }
        .page-header::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        .page-header-inner {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }
        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--white) 0%, var(--gray-100) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
        }
        .page-header .subtitle {
            color: var(--gray-400);
            font-size: 0.95rem;
        }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .kpi-card:hover {
            border-color: var(--card-hover-border);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.12),
                        inset 0 0 20px rgba(37, 99, 235, 0.03);
            transform: translateY(-3px);
        }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card .kpi-icon {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
        }
        .kpi-icon.gold {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(212, 175, 55, 0.2) 100%);
            color: var(--gold);
            border: 1px solid rgba(212, 175, 55, 0.2);
        }
        .kpi-icon.blue {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.2) 100%);
            color: var(--blue-light);
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
        .kpi-icon.green {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 197, 94, 0.2) 100%);
            color: var(--success);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        .kpi-icon.amber {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.2) 100%);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        .kpi-icon.cyan {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(6, 182, 212, 0.2) 100%);
            color: var(--info);
            border: 1px solid rgba(6, 182, 212, 0.2);
        }
        .kpi-card .kpi-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray-400);
            margin-bottom: 0.25rem;
        }
        .kpi-card .kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--white);
            line-height: 1.2;
        }
        .kpi-card .kpi-sub {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 0.25rem;
        }

        /* ===== EARNINGS CHART ===== */
        .chart-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        .chart-section::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .chart-header h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--white);
        }
        .chart-legend {
            display: flex;
            gap: 1.25rem;
            font-size: 0.8rem;
        }
        .chart-legend span {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            color: var(--gray-400);
        }
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
        }
        .legend-dot.earned { background: var(--gold); }
        .legend-dot.paid { background: var(--success); }

        .chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            height: 180px;
            padding-bottom: 2rem;
            position: relative;
        }
        .chart-bar-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
            position: relative;
        }
        .chart-bar-wrapper {
            flex: 1;
            width: 100%;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 3px;
        }
        .chart-bar {
            width: 45%;
            max-width: 28px;
            border-radius: 3px 3px 0 0;
            min-height: 3px;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            cursor: pointer;
        }
        .chart-bar:hover {
            filter: brightness(1.3);
        }
        .chart-bar.earned-bar {
            background: linear-gradient(180deg, var(--gold-light), var(--gold-dark));
        }
        .chart-bar.paid-bar {
            background: linear-gradient(180deg, #4ade80, #16a34a);
        }
        .chart-bar-label {
            font-size: 0.65rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
            text-align: center;
            white-space: nowrap;
        }
        .chart-bar-tooltip {
            display: none;
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: var(--black-lighter);
            border: 1px solid var(--card-border);
            border-radius: 6px;
            padding: 0.4rem 0.6rem;
            font-size: 0.7rem;
            color: var(--white);
            white-space: nowrap;
            z-index: 10;
            pointer-events: none;
        }
        .chart-bar:hover .chart-bar-tooltip {
            display: block;
        }
        .chart-no-data {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 180px;
            color: var(--gray-500);
            font-size: 0.9rem;
        }

        /* ===== FILTER BAR ===== */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .filter-bar .search-box {
            flex: 1;
            min-width: 220px;
            position: relative;
        }
        .filter-bar .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
            font-size: 0.85rem;
        }
        .filter-bar .search-box input {
            width: 100%;
            padding: 0.65rem 0.9rem 0.65rem 2.5rem;
            background: var(--black-lighter);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            color: var(--white);
            font-size: 0.85rem;
            transition: border-color 0.3s;
        }
        .filter-bar .search-box input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .filter-bar .search-box input::placeholder {
            color: var(--gray-500);
        }
        .status-filter-group {
            display: flex;
            gap: 0.35rem;
        }
        .status-filter-btn {
            padding: 0.5rem 1rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            background: transparent;
            color: var(--gray-400);
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.25s;
            white-space: nowrap;
        }
        .status-filter-btn:hover {
            background: rgba(255, 255, 255, 0.04);
            color: var(--white);
            border-color: rgba(255, 255, 255, 0.15);
        }
        .status-filter-btn.active {
            background: rgba(37, 99, 235, 0.1);
            color: var(--blue-light);
            border-color: rgba(37, 99, 235, 0.3);
        }
        .status-filter-btn .count-badge {
            font-size: 0.65rem;
            background: rgba(255, 255, 255, 0.06);
            padding: 0.1rem 0.4rem;
            border-radius: 10px;
            margin-left: 0.35rem;
        }
        .status-filter-btn.active .count-badge {
            background: rgba(37, 99, 235, 0.2);
        }

        /* ===== COMMISSION TABLE ===== */
        .commission-table-wrapper {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        .commission-table-wrapper::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        .table-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }
        .table-header-bar h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--white);
        }
        .table-header-bar .result-count {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .commission-table {
            width: 100%;
            border-collapse: collapse;
        }
        .commission-table thead th {
            padding: 0.85rem 1rem;
            text-align: left;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--gray-400);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(0, 0, 0, 0.3);
            white-space: nowrap;
        }
        .commission-table tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            transition: background 0.2s;
        }
        .commission-table tbody tr:hover {
            background: rgba(37, 99, 235, 0.04);
        }
        .commission-table tbody td {
            padding: 1rem;
            font-size: 0.875rem;
            color: var(--gray-200);
            vertical-align: middle;
        }

        /* Property cell */
        .property-cell {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }
        .property-type-icon {
            width: 38px;
            height: 38px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.2) 100%);
            color: var(--blue-light);
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
        .property-address {
            font-weight: 600;
            color: var(--white);
            font-size: 0.85rem;
            line-height: 1.3;
        }
        .property-type-label {
            font-size: 0.72rem;
            color: var(--gray-500);
            margin-top: 0.15rem;
        }

        /* Buyer cell */
        .buyer-cell {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .buyer-name {
            font-weight: 600;
            color: var(--white);
            font-size: 0.85rem;
        }
        .buyer-contact {
            font-size: 0.72rem;
            color: var(--gray-500);
        }

        /* Amount cells */
        .amount-sale {
            font-weight: 700;
            color: var(--white);
            font-size: 0.9rem;
        }
        .amount-commission {
            font-weight: 800;
            color: var(--gold);
            font-size: 0.95rem;
        }
        .commission-rate {
            font-size: 0.72rem;
            color: var(--gray-500);
            margin-top: 0.15rem;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.35rem 0.75rem;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-paid {
            background: rgba(34, 197, 94, 0.12);
            color: var(--success);
            border: 1px solid rgba(34, 197, 94, 0.25);
        }
        .status-calculated {
            background: rgba(212, 175, 55, 0.12);
            color: var(--gold);
            border: 1px solid rgba(212, 175, 55, 0.25);
        }
        .status-pending {
            background: rgba(245, 158, 11, 0.12);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.25);
        }
        .status-cancelled {
            background: rgba(239, 68, 68, 0.12);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.25);
        }

        /* Date cell */
        .date-primary {
            font-weight: 600;
            color: var(--white);
            font-size: 0.85rem;
        }
        .date-secondary {
            font-size: 0.7rem;
            color: var(--gray-500);
            margin-top: 0.1rem;
        }

        /* Payment ref */
        .payment-ref {
            font-family: 'Courier New', monospace;
            font-size: 0.78rem;
            color: var(--gray-300);
            background: rgba(255, 255, 255, 0.04);
            padding: 0.2rem 0.5rem;
            border-radius: 3px;
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        /* Detail button */
        .btn-detail {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: transparent;
            color: var(--gray-400);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-detail:hover {
            background: rgba(37, 99, 235, 0.1);
            color: var(--blue-light);
            border-color: rgba(37, 99, 235, 0.3);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        .empty-state-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08) 0%, rgba(37, 99, 235, 0.08) 100%);
            border: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--gray-500);
        }
        .empty-state h4 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            color: var(--gray-500);
            font-size: 0.9rem;
            max-width: 380px;
            margin: 0 auto;
        }

        /* ===== DETAIL MODAL ===== */
        .modal-content {
            background: var(--black-light);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            color: var(--white);
        }
        .modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            padding: 1.25rem 1.5rem;
        }
        .modal-header .modal-title {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--white);
        }
        .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
            opacity: 0.5;
        }
        .modal-body {
            padding: 1.5rem;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label {
            font-size: 0.8rem;
            color: var(--gray-400);
            font-weight: 500;
        }
        .detail-value {
            font-size: 0.9rem;
            color: var(--white);
            font-weight: 600;
            text-align: right;
        }
        .detail-section-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gold);
            margin-top: 1rem;
            margin-bottom: 0.5rem;
            padding-bottom: 0.35rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.15);
        }
        .detail-section-title:first-child { margin-top: 0; }

        /* Commission highlight in modal */
        .commission-highlight {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08), rgba(212, 175, 55, 0.03));
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 6px;
            padding: 1rem;
            text-align: center;
            margin-top: 1rem;
        }
        .commission-highlight .big-amount {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gold);
        }
        .commission-highlight .big-label {
            font-size: 0.75rem;
            color: var(--gray-400);
            margin-top: 0.25rem;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .kpi-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 992px) {
            .commission-content { padding: 1.5rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .page-header { padding: 1.5rem; }
            .chart-bars { height: 140px; }
        }
        @media (max-width: 768px) {
            .commission-content { padding: 1rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .page-header-inner { flex-direction: column; align-items: flex-start; }
            .filter-bar { flex-direction: column; }
            .status-filter-group { flex-wrap: wrap; }
            .commission-table-wrapper { overflow-x: auto; }
            .commission-table { min-width: 900px; }
        }
        @media (max-width: 576px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Agent Commissions (Dark Theme)
           ================================================================ */
        @keyframes sk-shimmer {
            0%   { background-position: -1600px 0; }
            100% { background-position:  1600px 0; }
        }
        .sk-shimmer {
            background: linear-gradient(90deg,
                rgba(255,255,255,0.03) 25%,
                rgba(255,255,255,0.06) 50%,
                rgba(255,255,255,0.03) 75%);
            background-size: 1600px 100%;
            animation: sk-shimmer 1.6s infinite linear;
            border-radius: 4px;
        }
        #page-content { display: none; }

        .sk-page-header {
            background: rgba(26,26,26,0.8);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 1.75rem 2rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sk-page-header-left { display:flex; flex-direction:column; gap:10px; }

        .sk-kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .sk-kpi-card {
            background: rgba(26,26,26,0.8);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .sk-chart-section {
            background: rgba(26,26,26,0.8);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .sk-chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 0.5rem;
            height: 120px;
            margin-top: 1.25rem;
        }
        .sk-chart-bar-col { flex:1; display:flex; flex-direction:column-reverse; align-items:center; gap:4px; }

        .sk-filter-bar {
            background: rgba(26,26,26,0.8);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 10px;
            padding: 0.875rem 1.25rem;
            margin-bottom: 1rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .sk-table-wrapper {
            background: rgba(26,26,26,0.8);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            overflow: hidden;
        }
        .sk-table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sk-table-row {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }
        .sk-line { display: block; border-radius: 4px; }

        /* Dark toast system */
        #toastContainer {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-width: 380px;
            width: 100%;
        }
        .app-toast {
            background: linear-gradient(135deg, rgba(26,26,26,0.97), rgba(15,15,15,0.97));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 1rem 1.1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.6), 0 2px 8px rgba(0,0,0,0.4);
            backdrop-filter: blur(12px);
            opacity: 0;
            transform: translateX(100%);
            transition: opacity 0.3s ease, transform 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .app-toast.show { opacity: 1; transform: translateX(0); }
        .app-toast.hide { opacity: 0; transform: translateX(calc(100% + 2rem)); }
        .app-toast::before {
            content: '';
            position: absolute; top: 0; left: 0;
            width: 4px; height: 100%;
            border-radius: 12px 0 0 12px;
        }
        .app-toast.toast-success::before { background: #22c55e; }
        .app-toast.toast-error::before   { background: #ef4444; }
        .app-toast.toast-info::before    { background: #2563eb; }
        .app-toast.toast-warning::before { background: #d4af37; }
        .toast-icon { font-size: 1.1rem; margin-top: 1px; flex-shrink: 0; }
        .app-toast.toast-success .toast-icon { color: #22c55e; }
        .app-toast.toast-error   .toast-icon { color: #ef4444; }
        .app-toast.toast-info    .toast-icon { color: #60a5fa; }
        .app-toast.toast-warning .toast-icon { color: #d4af37; }
        .toast-body { flex: 1; min-width: 0; }
        .toast-title { font-size: 0.875rem; font-weight: 600; color: #f1f5f9; margin-bottom: 2px; }
        .toast-msg   { font-size: 0.8rem; color: #9ca4ab; line-height: 1.5; }
        .toast-dismiss {
            background: none; border: none; color: #6b7280;
            font-size: 1rem; cursor: pointer; padding: 0; flex-shrink: 0; line-height: 1;
        }
        .toast-dismiss:hover { color: #d1d5db; }

        @media (max-width: 1200px) { .sk-kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px)  {
            .sk-kpi-grid   { grid-template-columns: 1fr 1fr; }
            .sk-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .sk-chart-bars  { height: 80px; }
        }
    </style>
</head>
<body>
    <?php include 'logout_agent_modal.php'; ?>

    <?php
    $active_page = 'agent_commissions.php';
    include 'agent_navbar.php';
    ?>

    <noscript><style>
        #sk-screen    { display: none !important; }
        #page-content { display: block !important; opacity: 1 !important; }
    </style></noscript>

    <div id="sk-screen" role="presentation" aria-hidden="true">
    <main class="commission-content">

        <!-- sk: page header -->
        <div class="sk-page-header">
            <div class="sk-page-header-left">
                <div class="sk-shimmer sk-line" style="width:220px;height:22px;"></div>
                <div class="sk-shimmer sk-line" style="width:420px;height:13px;"></div>
            </div>
            <div class="sk-shimmer sk-line" style="width:150px;height:16px;"></div>
        </div>

        <!-- sk: 5-col KPI grid -->
        <div class="sk-kpi-grid">
            <?php for($i=0;$i<5;$i++): ?>
            <div class="sk-kpi-card">
                <div class="sk-shimmer" style="width:44px;height:44px;border-radius:10px;"></div>
                <div class="sk-shimmer sk-line" style="width:65%;height:11px;"></div>
                <div class="sk-shimmer sk-line" style="width:70%;height:24px;"></div>
                <div class="sk-shimmer sk-line" style="width:50%;height:10px;"></div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- sk: chart section -->
        <div class="sk-chart-section">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div class="sk-shimmer sk-line" style="width:260px;height:16px;"></div>
                <div class="sk-shimmer sk-line" style="width:100px;height:14px;"></div>
            </div>
            <div class="sk-chart-bars">
                <?php
                $bar_heights = [38,55,45,72,60,85,50,65,40,78,55,90];
                foreach($bar_heights as $bh):
                ?>
                <div class="sk-chart-bar-col">
                    <div class="sk-shimmer" style="width:100%;height:<?php echo $bh; ?>px;border-radius:4px 4px 0 0;"></div>
                    <div class="sk-shimmer sk-line" style="width:90%;height:8px;margin-top:4px;"></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- sk: filter bar -->
        <div class="sk-filter-bar">
            <div class="sk-shimmer" style="flex:1;height:36px;border-radius:8px;"></div>
            <?php for($i=0;$i<5;$i++): ?>
            <div class="sk-shimmer" style="width:82px;height:32px;border-radius:20px;flex-shrink:0;"></div>
            <?php endfor; ?>
        </div>

        <!-- sk: commission table -->
        <div class="sk-table-wrapper">
            <div class="sk-table-header">
                <div class="sk-shimmer sk-line" style="width:180px;height:16px;"></div>
                <div class="sk-shimmer sk-line" style="width:80px;height:13px;"></div>
            </div>
            <?php for($i=0;$i<6;$i++): ?>
            <div class="sk-table-row">
                <div style="flex:2;display:flex;flex-direction:column;gap:6px;">
                    <div class="sk-shimmer sk-line" style="width:75%;height:13px;"></div>
                    <div class="sk-shimmer sk-line" style="width:45%;height:11px;"></div>
                </div>
                <div style="flex:1.5;display:flex;flex-direction:column;gap:6px;">
                    <div class="sk-shimmer sk-line" style="width:80%;height:13px;"></div>
                    <div class="sk-shimmer sk-line" style="width:55%;height:11px;"></div>
                </div>
                <div style="flex:1;"><div class="sk-shimmer sk-line" style="width:80%;height:18px;"></div></div>
                <div style="flex:1;"><div class="sk-shimmer" style="width:70px;height:22px;border-radius:20px;"></div></div>
                <div style="flex:1;display:flex;flex-direction:column;gap:6px;">
                    <div class="sk-shimmer sk-line" style="width:80%;height:12px;"></div>
                    <div class="sk-shimmer sk-line" style="width:55%;height:11px;"></div>
                </div>
                <div style="flex:0 0 50px;"><div class="sk-shimmer" style="width:44px;height:32px;border-radius:8px;"></div></div>
            </div>
            <?php endfor; ?>
        </div>

    </main>
    </div><!-- /#sk-screen -->

    <div id="page-content">
    <main class="commission-content">
        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1><i class="fas fa-hand-holding-usd me-2"></i>My Commissions</h1>
                    <p class="subtitle">Track your earnings, payment status, and commission history from property sales</p>
                </div>
                <div style="display:flex;gap:0.75rem;align-items:center;">
                    <span style="font-size:0.8rem;color:var(--gray-500);"><i class="fas fa-sync-alt me-1"></i>Updated <?php echo date('M j, Y'); ?></span>
                </div>
            </div>
        </div>

        <!-- KPI STATS -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="fas fa-coins"></i></div>
                <div class="kpi-label">Total Earnings</div>
                <div class="kpi-value">₱<?php echo number_format($totalEarnings, 2); ?></div>
                <div class="kpi-sub"><?php echo count($rows); ?> transaction<?php echo count($rows) !== 1 ? 's' : ''; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="kpi-label">Paid Out</div>
                <div class="kpi-value">₱<?php echo number_format($totalPaid, 2); ?></div>
                <div class="kpi-sub"><?php echo $paidCount; ?> paid commission<?php echo $paidCount !== 1 ? 's' : ''; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fas fa-hourglass-half"></i></div>
                <div class="kpi-label">Awaiting Payment</div>
                <div class="kpi-value">₱<?php echo number_format($totalPending + $totalCalculated, 2); ?></div>
                <div class="kpi-sub"><?php echo ($pendingCount + $calculatedCount); ?> outstanding</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fas fa-chart-line"></i></div>
                <div class="kpi-label">Sales Volume</div>
                <div class="kpi-value">₱<?php echo number_format($totalSalesVolume, 0); ?></div>
                <div class="kpi-sub">Total property sales</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon cyan"><i class="fas fa-percentage"></i></div>
                <div class="kpi-label">Avg Commission Rate</div>
                <div class="kpi-value"><?php echo number_format($avgRate, 2); ?>%</div>
                <div class="kpi-sub">Across all sales</div>
            </div>
        </div>

        <!-- EARNINGS CHART -->
        <div class="chart-section">
            <div class="chart-header">
                <h3><i class="fas fa-chart-bar me-2" style="color:var(--gold);"></i>Monthly Earnings (Last 12 Months)</h3>
                <div class="chart-legend">
                    <span><span class="legend-dot earned"></span> Earned</span>
                    <span><span class="legend-dot paid"></span> Paid</span>
                </div>
            </div>
            <?php
            $maxVal = max(1, max(array_column($monthlyData, 'earned')));
            $hasChartData = array_sum(array_column($monthlyData, 'earned')) > 0;
            ?>
            <?php if ($hasChartData): ?>
            <div class="chart-bars">
                <?php foreach ($monthlyData as $m): ?>
                <div class="chart-bar-group">
                    <div class="chart-bar-wrapper">
                        <div class="chart-bar earned-bar" style="height: <?php echo max(2, ($m['earned'] / $maxVal) * 100); ?>%;">
                            <div class="chart-bar-tooltip">₱<?php echo number_format($m['earned'], 0); ?></div>
                        </div>
                        <div class="chart-bar paid-bar" style="height: <?php echo $m['paid'] > 0 ? max(2, ($m['paid'] / $maxVal) * 100) : 0; ?>%;">
                            <div class="chart-bar-tooltip">₱<?php echo number_format($m['paid'], 0); ?></div>
                        </div>
                    </div>
                    <div class="chart-bar-label"><?php echo $m['short']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="chart-no-data">
                <div><i class="fas fa-chart-bar me-2" style="opacity:0.3;"></i>No earnings data yet — chart will populate after your first commission</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="commissionSearch" placeholder="Search by property, buyer, reference..." autocomplete="off">
            </div>
            <div class="status-filter-group">
                <button class="status-filter-btn active" data-filter="all">All <span class="count-badge"><?php echo count($rows); ?></span></button>
                <button class="status-filter-btn" data-filter="paid">Paid <span class="count-badge"><?php echo $paidCount; ?></span></button>
                <button class="status-filter-btn" data-filter="calculated">Calculated <span class="count-badge"><?php echo $calculatedCount; ?></span></button>
                <button class="status-filter-btn" data-filter="pending">Pending <span class="count-badge"><?php echo $pendingCount; ?></span></button>
            </div>
        </div>

        <!-- COMMISSION TABLE -->
        <div class="commission-table-wrapper">
            <div class="table-header-bar">
                <h3><i class="fas fa-receipt me-2" style="color:var(--gold);font-size:0.9rem;"></i>Commission Records</h3>
                <span class="result-count" id="resultCount"><?php echo count($rows); ?> record<?php echo count($rows) !== 1 ? 's' : ''; ?></span>
            </div>

            <?php if (empty($rows)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <h4>No Commissions Yet</h4>
                <p>Your commission records will appear here once property sales are finalized and processed by the admin.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="commission-table">
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Buyer</th>
                            <th>Sale Date</th>
                            <th>Sale Price</th>
                            <th>Commission</th>
                            <th>Status</th>
                            <th>Payment Date</th>
                            <th>Reference</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="commissionTableBody">
                        <?php foreach ($rows as $i => $r):
                            $address = trim(($r['StreetAddress'] ?? '') . ', ' . ($r['City'] ?? ''));
                            $address = ($address !== ',') ? $address : 'Property #' . (int)$r['property_id'];
                            $propType = $r['PropertyType'] ?? 'Property';
                            $statusLower = strtolower($r['status']);
                            
                            // Property type icon
                            $typeIcon = 'fas fa-building';
                            $typeLower = strtolower($propType);
                            if (strpos($typeLower, 'house') !== false || strpos($typeLower, 'single') !== false) $typeIcon = 'fas fa-home';
                            elseif (strpos($typeLower, 'condo') !== false || strpos($typeLower, 'apartment') !== false) $typeIcon = 'fas fa-city';
                            elseif (strpos($typeLower, 'land') !== false || strpos($typeLower, 'lot') !== false) $typeIcon = 'fas fa-map';
                            elseif (strpos($typeLower, 'commercial') !== false) $typeIcon = 'fas fa-store';
                            elseif (strpos($typeLower, 'townhouse') !== false) $typeIcon = 'fas fa-house-user';

                            // Status badge
                            $statusClass = 'status-pending';
                            $statusIcon = 'fas fa-clock';
                            $statusLabel = ucfirst($r['status']);
                            if ($statusLower === 'paid') { $statusClass = 'status-paid'; $statusIcon = 'fas fa-check-circle'; }
                            elseif ($statusLower === 'calculated') { $statusClass = 'status-calculated'; $statusIcon = 'fas fa-calculator'; }
                            elseif ($statusLower === 'cancelled') { $statusClass = 'status-cancelled'; $statusIcon = 'fas fa-times-circle'; }
                        ?>
                        <tr class="commission-row" 
                            data-status="<?php echo $statusLower; ?>"
                            data-search="<?php echo strtolower(htmlspecialchars($address . ' ' . ($r['buyer_name'] ?? '') . ' ' . $propType . ' ' . ($r['payment_reference'] ?? ''))); ?>">
                            <td>
                                <div class="property-cell">
                                    <div class="property-type-icon"><i class="<?php echo $typeIcon; ?>"></i></div>
                                    <div>
                                        <div class="property-address"><?php echo htmlspecialchars($address); ?></div>
                                        <div class="property-type-label"><?php echo htmlspecialchars($propType); ?><?php echo !empty($r['Bedrooms']) ? ' &middot; ' . $r['Bedrooms'] . 'bd' : ''; ?><?php echo !empty($r['Bathrooms']) ? '/' . rtrim(rtrim(number_format($r['Bathrooms'], 1), '0'), '.') . 'ba' : ''; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="buyer-cell">
                                    <span class="buyer-name"><?php echo htmlspecialchars($r['buyer_name'] ?? '—'); ?></span>
                                    <?php if (!empty($r['buyer_email'])): ?>
                                    <span class="buyer-contact"><i class="fas fa-envelope me-1" style="font-size:0.6rem;"></i><?php echo htmlspecialchars($r['buyer_email']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="date-primary"><?php echo $r['sale_date'] ? date('M j, Y', strtotime($r['sale_date'])) : '—'; ?></div>
                                <?php if ($r['calculated_at']): ?>
                                <div class="date-secondary">Processed <?php echo date('M j', strtotime($r['calculated_at'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="amount-sale">₱<?php echo number_format((float)$r['final_sale_price'], 2); ?></span>
                            </td>
                            <td>
                                <div class="amount-commission">₱<?php echo number_format((float)$r['commission_amount'], 2); ?></div>
                                <div class="commission-rate"><?php echo number_format((float)$r['commission_percentage'], 2); ?>% rate</div>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>"><i class="<?php echo $statusIcon; ?>" style="font-size:0.6rem;"></i> <?php echo $statusLabel; ?></span>
                            </td>
                            <td>
                                <?php if ($r['paid_at']): ?>
                                <div class="date-primary"><?php echo date('M j, Y', strtotime($r['paid_at'])); ?></div>
                                <?php else: ?>
                                <span style="color:var(--gray-600);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['payment_reference'])): ?>
                                <span class="payment-ref"><?php echo htmlspecialchars($r['payment_reference']); ?></span>
                                <?php else: ?>
                                <span style="color:var(--gray-600);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn-detail" onclick="showDetail(<?php echo $i; ?>)" title="View details">
                                    <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
    </div><!-- /#page-content -->

    <div id="toastContainer"></div>

    <!-- DETAIL MODAL -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2" style="color:var(--gold);"></i>Commission Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailModalBody">
                </div>
            </div>
        </div>
    </div>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
    // Commission data for modal
    const commissions = <?php echo json_encode(array_map(function($r) {
        return [
            'property'   => trim(($r['StreetAddress'] ?? '') . ', ' . ($r['City'] ?? '')),
            'state'      => $r['Province'] ?? '',
            'type'       => $r['PropertyType'] ?? 'N/A',
            'beds'       => $r['Bedrooms'] ?? null,
            'baths'      => $r['Bathrooms'] ?? null,
            'sqft'       => $r['SquareFootage'] ?? null,
            'listPrice'  => (float)($r['ListingPrice'] ?? 0),
            'salePrice'  => (float)$r['final_sale_price'],
            'saleDate'   => $r['sale_date'] ? date('M j, Y', strtotime($r['sale_date'])) : '—',
            'buyer'      => $r['buyer_name'] ?? '—',
            'buyerEmail' => $r['buyer_email'] ?? '',
            'rate'       => number_format((float)$r['commission_percentage'], 2),
            'amount'     => (float)$r['commission_amount'],
            'status'     => ucfirst($r['status']),
            'calculatedAt' => $r['calculated_at'] ? date('M j, Y g:i A', strtotime($r['calculated_at'])) : '—',
            'paidAt'     => $r['paid_at'] ? date('M j, Y g:i A', strtotime($r['paid_at'])) : null,
            'reference'  => $r['payment_reference'] ?? null,
            'notes'      => $r['additional_notes'] ?? null,
        ];
    }, $rows)); ?>;

    function showDetail(index) {
        const c = commissions[index];
        if (!c) return;

        let html = '';

        // Property section
        html += `<div class="detail-section-title"><i class="fas fa-home me-1"></i> Property Information</div>`;
        html += detailRow('Address', c.property + (c.state ? ', ' + c.state : ''));
        html += detailRow('Type', c.type);
        if (c.beds || c.baths) html += detailRow('Bed / Bath', (c.beds || '—') + ' bd / ' + (c.baths || '—') + ' ba');
        if (c.sqft) html += detailRow('Square Footage', Number(c.sqft).toLocaleString() + ' sqft');
        if (c.listPrice) html += detailRow('Listing Price', '₱' + Number(c.listPrice).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}));

        // Sale section
        html += `<div class="detail-section-title"><i class="fas fa-handshake me-1"></i> Sale Details</div>`;
        html += detailRow('Sale Price', '₱' + Number(c.salePrice).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}));
        html += detailRow('Sale Date', c.saleDate);
        if (c.listPrice && c.salePrice) {
            const diff = c.salePrice - c.listPrice;
            const pct = c.listPrice > 0 ? ((diff / c.listPrice) * 100).toFixed(1) : 0;
            const arrow = diff >= 0 ? '↑' : '↓';
            const color = diff >= 0 ? 'var(--success)' : 'var(--danger)';
            html += detailRow('Price vs Listing', `<span style="color:${color}">${arrow} ₱${Math.abs(diff).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} (${Math.abs(pct)}%)</span>`);
        }

        // Buyer section
        html += `<div class="detail-section-title"><i class="fas fa-user me-1"></i> Buyer Information</div>`;
        html += detailRow('Name', c.buyer);
        if (c.buyerEmail) html += detailRow('Email', `<a href="mailto:${c.buyerEmail}" style="color:var(--blue-light);text-decoration:none;">${c.buyerEmail}</a>`);

        // Commission section
        html += `<div class="detail-section-title"><i class="fas fa-coins me-1"></i> Commission Details</div>`;
        html += detailRow('Commission Rate', c.rate + '%');
        html += detailRow('Status', c.status);
        html += detailRow('Calculated At', c.calculatedAt);
        if (c.paidAt) html += detailRow('Paid At', c.paidAt);
        if (c.reference) html += detailRow('Payment Reference', `<span class="payment-ref">${c.reference}</span>`);
        if (c.notes) html += detailRow('Notes', c.notes);

        // Commission highlight
        html += `<div class="commission-highlight">
            <div class="big-amount">₱${Number(c.amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
            <div class="big-label">Commission Earned at ${c.rate}% Rate</div>
        </div>`;

        document.getElementById('detailModalBody').innerHTML = html;
        new bootstrap.Modal(document.getElementById('detailModal')).show();
    }

    function detailRow(label, value) {
        return `<div class="detail-row"><span class="detail-label">${label}</span><span class="detail-value">${value}</span></div>`;
    }

    // ===== REAL-TIME SEARCH & FILTER =====
    const searchInput = document.getElementById('commissionSearch');
    const filterBtns = document.querySelectorAll('.status-filter-btn');
    const tableRows = document.querySelectorAll('.commission-row');
    const resultCount = document.getElementById('resultCount');
    let activeFilter = 'all';

    function applyFilters() {
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        let visible = 0;

        tableRows.forEach(row => {
            const matchesStatus = activeFilter === 'all' || row.dataset.status === activeFilter;
            const matchesSearch = !query || row.dataset.search.includes(query);
            const show = matchesStatus && matchesSearch;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (resultCount) {
            resultCount.textContent = visible + ' record' + (visible !== 1 ? 's' : '');
        }
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeFilter = btn.dataset.filter;
            applyFilters();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }
    </script>

    <script>
    function showToast(type, title, message, duration) {
        const icons = { success:'bi bi-check-circle-fill', error:'bi bi-x-circle-fill', warning:'bi bi-exclamation-triangle-fill', info:'bi bi-info-circle-fill' };
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `app-toast toast-${type}`;
        toast.innerHTML = `<i class="${icons[type] || icons.info} toast-icon"></i><div class="toast-body"><div class="toast-title">${title}</div><div class="toast-msg">${message}</div></div><button class="toast-dismiss" onclick="dismissToast(this)" aria-label="Dismiss">&times;</button>`;
        container.appendChild(toast);
        requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('show')));
        if (duration) setTimeout(() => dismissToast(toast.querySelector('.toast-dismiss')), duration);
    }
    function dismissToast(btn) {
        const toast = btn.closest ? btn.closest('.app-toast') : btn.parentElement;
        if (!toast) return;
        toast.classList.remove('show');
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 350);
    }
    </script>

    <script>
    /* ── Skeleton hydration ── */
    (function () {
        const MIN_SKELETON_MS = 400;
        const t0 = Date.now();
        const skScreen    = document.getElementById('sk-screen');
        const pageContent = document.getElementById('page-content');
        function hydrate() {
            const elapsed   = Date.now() - t0;
            const remaining = Math.max(0, MIN_SKELETON_MS - elapsed);
            setTimeout(function () {
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        if (skScreen) {
                            skScreen.style.transition = 'opacity 0.25s ease';
                            skScreen.style.opacity    = '0';
                            setTimeout(function () { skScreen.style.display = 'none'; }, 250);
                        }
                        if (pageContent) {
                            pageContent.style.display    = 'block';
                            pageContent.style.opacity    = '0';
                            pageContent.style.transition = 'opacity 0.35s ease';
                            requestAnimationFrame(function () {
                                requestAnimationFrame(function () {
                                    pageContent.style.opacity = '1';
                                });
                            });
                        }
                        document.dispatchEvent(new CustomEvent('skeleton:hydrated'));
                    });
                });
            }, remaining);
        }
        if (document.readyState === 'complete') { hydrate(); }
        else { window.addEventListener('load', hydrate); }
    }());
    </script>

    <script>
    document.addEventListener('skeleton:hydrated', function () {
        <?php $outstanding = $pendingCount + $calculatedCount; ?>
        <?php if ($outstanding > 0): ?>
        showToast('warning', 'Commissions Awaiting Payment',
            '<?php echo $outstanding; ?> commission<?php echo $outstanding !== 1 ? "s" : ""; ?> totalling ₱<?php echo number_format($totalPending + $totalCalculated, 2); ?> are awaiting payment.',
            7000);
        <?php endif; ?>
    });
    </script>
</body>
</html>
