<?php
session_start();
include 'connection.php';
require_once __DIR__ . '/config/paths.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$account_id = (int)$_SESSION['account_id'];

// Fetch admin account + profile info
$stmt = $conn->prepare("
    SELECT a.account_id, a.first_name, a.middle_name, a.last_name, a.phone_number, a.email, a.username, a.date_registered, a.is_active, a.two_factor_enabled,
           ai.admin_info_id, ai.license_number, ai.specialization, ai.years_experience, ai.bio, ai.profile_picture_url, ai.profile_completed
    FROM accounts a
    LEFT JOIN admin_information ai ON a.account_id = ai.account_id
    WHERE a.account_id = ? LIMIT 1
");
$stmt->bind_param("i", $account_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Summary stats
$total_properties = 0;
$total_agents = 0;
$total_tours = 0;
$total_sales = 0;

$r = $conn->query("SELECT COUNT(*) AS c FROM property");
if ($r) $total_properties = $r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM agent_information WHERE profile_completed = 1");
if ($r) $total_agents = $r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM tour_requests");
if ($r) $total_tours = $r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM finalized_sales");
if ($r) $total_sales = $r->fetch_assoc()['c'];

// Last login timestamp
$last_login_ts = null;
$ll = $conn->prepare("SELECT log_timestamp FROM admin_logs WHERE admin_account_id = ? AND action = 'login' ORDER BY log_timestamp DESC LIMIT 1");
$ll->bind_param("i", $account_id);
$ll->execute();
$ll_res = $ll->get_result();
if ($row = $ll_res->fetch_assoc()) $last_login_ts = $row['log_timestamp'];
$ll->close();

// ===== EXTRA STATS for Platform Activity =====
$pending_tours     = 0;
$confirmed_tours   = 0;
$completed_tours   = 0;
$pending_approvals = 0;
$pending_sales     = 0;
$for_sale_count    = 0;
$for_rent_count    = 0;
$sold_count        = 0;

$r = $conn->query("SELECT COUNT(*) AS c FROM tour_requests WHERE request_status = 'Pending'");
if ($r) $pending_tours = (int)$r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM tour_requests WHERE request_status = 'Confirmed'");
if ($r) $confirmed_tours = (int)$r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM tour_requests WHERE request_status = 'Completed'");
if ($r) $completed_tours = (int)$r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM property WHERE approval_status = 'pending'");
if ($r) $pending_approvals = (int)$r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM sale_verifications WHERE status = 'Pending'");
if ($r) $pending_sales = (int)$r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM property WHERE Status = 'For Sale' AND approval_status = 'approved'");
if ($r) $for_sale_count = (int)$r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM property WHERE Status = 'For Rent' AND approval_status = 'approved'");
if ($r) $for_rent_count = (int)$r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM property WHERE Status = 'Sold'");
if ($r) $sold_count = (int)$r->fetch_assoc()['c'];

// Top property types breakdown
$property_types = [];
$r = $conn->query("SELECT PropertyType, COUNT(*) AS cnt FROM property WHERE approval_status = 'approved' GROUP BY PropertyType ORDER BY cnt DESC LIMIT 5");
if ($r) { while ($row = $r->fetch_assoc()) $property_types[] = $row; }

// Latest finalized sale
$latest_sale = null;
$r = $conn->query("SELECT fs.sale_date, fs.final_sale_price, fs.buyer_name, p.StreetAddress, p.City, p.PropertyType FROM finalized_sales fs LEFT JOIN property p ON p.property_ID = fs.property_id ORDER BY fs.finalized_at DESC LIMIT 1");
if ($r) $latest_sale = $r->fetch_assoc();

// Upcoming tours (next 7 days)
$upcoming_tours = [];
$r = $conn->query("SELECT tr.tour_date, tr.tour_time, tr.tour_type, tr.user_name, p.StreetAddress, p.City FROM tour_requests tr LEFT JOIN property p ON p.property_ID = tr.property_id WHERE tr.request_status = 'Confirmed' AND tr.tour_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY tr.tour_date ASC, tr.tour_time ASC LIMIT 5");
if ($r) { while ($row = $r->fetch_assoc()) $upcoming_tours[] = $row; }

// ===== ADMIN COMMISSIONS (admin also acts as agent) =====
$comm_rows        = [];
$comm_earnings    = 0.0;
$comm_paid        = 0.0;
$comm_pending     = 0.0;
$comm_calculated  = 0.0;
$comm_salesVol    = 0.0;
$comm_paidCount   = 0;
$comm_pendCount   = 0;
$comm_calcCount   = 0;
$comm_cancelCount = 0;

$cs = $conn->prepare("
    SELECT
        ac.commission_id, ac.sale_id, ac.agent_id,
        ac.commission_amount, ac.commission_percentage, ac.status,
        ac.calculated_at, ac.paid_at, ac.payment_reference, ac.created_at,
        fs.property_id, fs.final_sale_price, fs.sale_date,
        fs.buyer_name, fs.buyer_email, fs.additional_notes, fs.finalized_at,
        p.StreetAddress, p.City, p.Province, p.PropertyType,
        p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice
    FROM agent_commissions ac
    JOIN finalized_sales fs ON fs.sale_id = ac.sale_id
    LEFT JOIN property p ON p.property_ID = fs.property_id
    WHERE ac.agent_id = ?
    ORDER BY ac.created_at DESC
");
$cs->bind_param('i', $account_id);
$cs->execute();
$cs_res = $cs->get_result();
while ($cr = $cs_res->fetch_assoc()) {
    $comm_rows[] = $cr;
    $amt = (float)$cr['commission_amount'];
    $comm_earnings += $amt;
    $comm_salesVol += (float)$cr['final_sale_price'];
    switch (strtolower($cr['status'])) {
        case 'paid':        $comm_paid       += $amt; $comm_paidCount++;   break;
        case 'pending':     $comm_pending    += $amt; $comm_pendCount++;   break;
        case 'calculated':  $comm_calculated += $amt; $comm_calcCount++;   break;
        case 'cancelled':   $comm_cancelCount++; break;
    }
}
$cs->close();

$comm_avgRate = count($comm_rows) > 0 ? array_sum(array_column($comm_rows, 'commission_percentage')) / count($comm_rows) : 0;

// Monthly earnings for chart (last 12 months)
$comm_monthly = [];
for ($i = 11; $i >= 0; $i--) {
    $mk = date('Y-m', strtotime("-{$i} months"));
    $comm_monthly[$mk] = ['earned' => 0, 'paid' => 0, 'short' => date('M', strtotime("-{$i} months"))];
}
foreach ($comm_rows as $cr) {
    $dRef = $cr['calculated_at'] ?? $cr['sale_date'] ?? $cr['created_at'];
    if ($dRef) {
        $mk = date('Y-m', strtotime($dRef));
        if (isset($comm_monthly[$mk])) {
            $comm_monthly[$mk]['earned'] += (float)$cr['commission_amount'];
            if (strtolower($cr['status']) === 'paid') $comm_monthly[$mk]['paid'] += (float)$cr['commission_amount'];
        }
    }
}

// Fetch all specializations for multi-select
$all_specs = [];
$spec_r = $conn->query("SELECT specialization_id, specialization_name FROM specializations ORDER BY specialization_name ASC");
if ($spec_r) { while ($row = $spec_r->fetch_assoc()) $all_specs[] = $row; }

// Parse current admin specializations
$current_specs_raw = $admin['specialization'] ?? '';
$current_specs = array_filter(array_map('trim', explode(',', $current_specs_raw)));

// Helpers
$avatar_src = BASE_URL . 'images/placeholder-avatar.svg';
if (!empty($admin['profile_picture_url'])) {
    $avatar_src = htmlspecialchars($admin['profile_picture_url']);
}
$full_name = htmlspecialchars(trim(($admin['first_name'] ?? '') . ' ' . ($admin['middle_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')));
$years_exp = isset($admin['years_experience']) ? (int)$admin['years_experience'] : 0;
$experience_text = $years_exp === 0 ? 'New' : ($years_exp === 1 ? '1 Year' : $years_exp . ' Years');
$member_since = !empty($admin['date_registered']) ? date('F Y', strtotime($admin['date_registered'])) : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Admin Panel</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    <style>
        /* ===== BASE & LAYOUT ===== */
        :root {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --card-bg: #ffffff;
            --bg-light: #f8f9fa;
            --text-primary: #1a1f24;
            --text-secondary: #64748b;
            --border-light: rgba(37, 99, 235, 0.1);
            --border-gold: rgba(212, 175, 55, 0.2);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background-color: var(--bg-light); color: var(--text-primary); margin: 0; }
        .admin-sidebar { background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%); color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; z-index: 1000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .admin-content { margin-left: 290px; padding: 2rem 2.5rem; min-height: 100vh; max-width: 1600px; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; padding: 1.5rem; } }
        @media (max-width: 768px) { .admin-content { padding: 1rem; } }



        /* ===== PROFILE HERO ===== */
        .profile-hero {
            background: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 1.75rem;
        }
        .profile-hero-cover {
            height: 180px;
            background: linear-gradient(135deg, #0b1120 0%, #0f172a 30%, #1a2a6e 70%, #1e40af 100%);
            position: relative;
            overflow: hidden;
        }
        .cover-dot-grid {
            position: absolute; inset: 0;
            background-image: radial-gradient(circle, rgba(255,255,255,0.06) 1px, transparent 1px);
            background-size: 24px 24px;
        }
        .cover-glow-1 {
            position: absolute; width: 260px; height: 260px; border-radius: 50%;
            background: radial-gradient(circle, rgba(212,175,55,0.15) 0%, transparent 70%);
            top: -80px; right: 80px;
        }
        .cover-glow-2 {
            position: absolute; width: 200px; height: 200px; border-radius: 50%;
            background: radial-gradient(circle, rgba(37,99,235,0.2) 0%, transparent 70%);
            bottom: -60px; left: 140px;
        }
        .cover-line {
            position: absolute; top: 50%; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,0.15), rgba(37,99,235,0.25), transparent);
        }
        .profile-hero-cover::after {
            content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        .profile-hero-body {
            padding: 0 2.5rem 2rem;
            position: relative;
        }
        .profile-hero-layout {
            display: flex;
            align-items: flex-start;
            gap: 2rem;
            flex-wrap: wrap;
        }
        .profile-hero-avatar {
            width: 130px; height: 130px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #fff;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12), 0 0 0 3px rgba(37,99,235,0.15);
            margin-top: -65px;
            position: relative;
            z-index: 2;
            flex-shrink: 0;
        }
        .profile-hero-meta {
            flex: 1;
            min-width: 250px;
            padding-top: 0.75rem;
        }
        .profile-hero-name {
            font-size: 1.65rem;
            font-weight: 800;
            color: var(--text-primary);
            margin: 0 0 0.35rem;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .profile-hero-role {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.06), rgba(37, 99, 235, 0.12));
            color: var(--blue);
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.3rem 0.85rem;
            border-radius: 3px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border: 1px solid rgba(37, 99, 235, 0.12);
        }
        .profile-hero-email {
            font-size: 0.88rem;
            color: var(--text-secondary);
            margin-top: 0.6rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .profile-hero-badges {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.6rem;
        }
        .profile-hero-actions {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            padding-top: 1rem;
            flex-wrap: wrap;
        }

        /* ===== STATUS BADGES ===== */
        .badge-status {
            display: inline-flex; align-items: center; gap: 0.35rem;
            font-size: 0.75rem; font-weight: 700; padding: 0.28rem 0.7rem; border-radius: 3px;
        }
        .badge-active { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.18); }
        .badge-inactive { background: rgba(239,68,68,0.08); color: #dc2626; border: 1px solid rgba(239,68,68,0.18); }
        .badge-2fa { background: rgba(37,99,235,0.08); color: #2563eb; border: 1px solid rgba(37,99,235,0.18); }
        .badge-2fa-off { background: rgba(245,158,11,0.08); color: #d97706; border: 1px solid rgba(245,158,11,0.18); }

        /* ===== BUTTONS ===== */
        .btn-gold {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff; border: none;
            font-weight: 700; font-size: 0.85rem;
            padding: 0.6rem 1.35rem; border-radius: 4px;
            transition: all 0.25s ease;
            display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; cursor: pointer;
        }
        .btn-gold:hover { background: linear-gradient(135deg, var(--gold), var(--gold-light)); color: #fff; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,175,55,0.25); }
        .btn-outline-admin {
            border: 1px solid rgba(37,99,235,0.25); color: var(--blue); background: transparent;
            font-weight: 600; font-size: 0.85rem; padding: 0.6rem 1.35rem; border-radius: 4px;
            transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; cursor: pointer;
        }
        .btn-outline-admin:hover { background: rgba(37,99,235,0.06); border-color: var(--blue); color: var(--blue); }

        /* ===== QUICK STATS ROW ===== */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 6px;
            padding: 1.25rem 1.35rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            opacity: 0; transition: opacity 0.3s ease;
        }
        .stat-card:nth-child(1)::before { background: linear-gradient(90deg, var(--blue), var(--blue-light)); }
        .stat-card:nth-child(2)::before { background: linear-gradient(90deg, var(--gold-dark), var(--gold)); }
        .stat-card:nth-child(3)::before { background: linear-gradient(90deg, #16a34a, #4ade80); }
        .stat-card:nth-child(4)::before { background: linear-gradient(90deg, #0891b2, #22d3ee); }
        .stat-card:hover { border-color: rgba(37,99,235,0.2); box-shadow: 0 8px 28px rgba(37,99,235,0.06); transform: translateY(-3px); }
        .stat-card:hover::before { opacity: 1; }
        .stat-icon {
            width: 42px; height: 42px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; margin-bottom: 0.85rem;
        }
        .stat-icon.blue { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.12); }
        .stat-icon.gold { background: rgba(212,175,55,0.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.15); }
        .stat-icon.green { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.12); }
        .stat-icon.cyan { background: rgba(6,182,212,0.08); color: #0891b2; border: 1px solid rgba(6,182,212,0.12); }
        .stat-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-secondary); margin-bottom: 0.3rem; }
        .stat-value { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); line-height: 1; }
        @media (max-width: 992px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .stats-row { grid-template-columns: 1fr 1fr; gap: 0.65rem; } .stat-card { padding: 1rem; } .stat-value { font-size: 1.25rem; } }

        /* ===== CONTENT GRID: MAIN ONLY ===== */
        .profile-grid {
            display: block;
            margin-bottom: 1.75rem;
        }

        /* ===== CONTENT CARD ===== */
        .content-card {
            background: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            position: relative;
        }
        .content-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--blue) 0%, var(--gold) 50%, var(--blue) 100%);
            opacity: 0.4;
        }
        .card-header {
            padding: 1.15rem 1.5rem;
            border-bottom: 1px solid rgba(37,99,235,0.06);
            display: flex; align-items: center; gap: 0.65rem;
        }
        .card-header i { font-size: 1.05rem; color: var(--blue); }
        .card-header h3 { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0; }
        .card-body-inner { padding: 1.5rem; }

        /* ===== INFO ITEMS ===== */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        .info-grid .full-span { grid-column: 1 / -1; }
        @media (max-width: 900px) { .info-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .info-grid { grid-template-columns: repeat(2, 1fr); } }
        .info-item {
            background: rgba(37,99,235,0.015);
            border: 1px solid rgba(37,99,235,0.06);
            border-radius: 5px;
            padding: 1rem 1.15rem;
        }
        .info-label {
            font-size: 0.68rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 600;
            margin-bottom: 0.35rem;
            display: flex; align-items: center; gap: 0.35rem;
        }
        .info-label i { font-size: 0.7rem; color: var(--gold-dark); }
        .info-value {
            font-size: 0.95rem;
            color: var(--text-primary);
            font-weight: 600;
            word-break: break-word;
            line-height: 1.4;
        }

        /* ===== SPECIALIZATION TAGS ===== */
        .spec-tags { display: flex; flex-wrap: wrap; gap: 0.45rem; margin-top: 0.15rem; }
        .spec-tag {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.35rem 0.8rem;
            background: linear-gradient(135deg, rgba(212,175,55,0.06), rgba(212,175,55,0.14));
            border: 1px solid rgba(212,175,55,0.25);
            border-radius: 20px;
            font-size: 0.76rem; font-weight: 600; color: var(--gold-dark);
        }
        .spec-tag i { font-size: 0.62rem; }

        /* ===== PLATFORM ACTIVITY ===== */
        .activity-pending-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        @media (max-width: 900px) { .activity-pending-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .activity-pending-row { grid-template-columns: 1fr 1fr; gap: 0.5rem; } }
        .activity-pending-item {
            display: flex; align-items: center; gap: 0.7rem;
            padding: 0.85rem 0.95rem;
            background: color-mix(in srgb, var(--ap-color) 4%, white);
            border: 1px solid color-mix(in srgb, var(--ap-color) 15%, transparent);
            border-radius: 5px;
            transition: all 0.2s ease;
        }
        .activity-pending-item:hover {
            border-color: color-mix(in srgb, var(--ap-color) 30%, transparent);
            box-shadow: 0 4px 12px color-mix(in srgb, var(--ap-color) 8%, transparent);
        }
        .ap-icon {
            width: 36px; height: 36px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            background: color-mix(in srgb, var(--ap-color) 10%, white);
            color: var(--ap-color); font-size: 1rem;
            border: 1px solid color-mix(in srgb, var(--ap-color) 15%, transparent);
            flex-shrink: 0;
        }
        .ap-info { display: flex; flex-direction: column; min-width: 0; }
        .ap-count { font-size: 1.15rem; font-weight: 800; color: var(--text-primary); line-height: 1.1; }
        .ap-label { font-size: 0.62rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .activity-section-label {
            font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.8px; color: var(--text-secondary);
            margin-bottom: 0.75rem; padding-bottom: 0.45rem;
            border-bottom: 1px solid rgba(37,99,235,0.06);
            display: flex; align-items: center; gap: 0.4rem;
        }
        .activity-section-label i { font-size: 0.72rem; color: var(--blue); }

        .property-breakdown {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem;
        }
        @media (max-width: 480px) { .property-breakdown { grid-template-columns: 1fr; } }
        .pb-item {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.6rem 0.85rem;
            background: rgba(37,99,235,0.015);
            border: 1px solid rgba(37,99,235,0.06);
            border-radius: 4px;
        }
        .pb-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .pb-label { flex: 1; font-size: 0.82rem; color: var(--text-secondary); font-weight: 500; }
        .pb-value { font-size: 0.9rem; font-weight: 800; color: var(--text-primary); }

        .property-types-list { display: flex; flex-direction: column; gap: 0.55rem; }
        .pt-row { display: flex; align-items: center; gap: 0.75rem; }
        .pt-name { width: 120px; flex-shrink: 0; font-size: 0.82rem; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pt-bar-wrap { flex: 1; height: 6px; background: rgba(37,99,235,0.06); border-radius: 3px; overflow: hidden; }
        .pt-bar { height: 100%; background: linear-gradient(90deg, var(--blue), var(--gold)); border-radius: 3px; transition: width 0.6s ease; }
        .pt-count { font-size: 0.82rem; font-weight: 800; color: var(--text-primary); width: 28px; text-align: right; }

        /* ===== UPCOMING TOURS ===== */
        .upcoming-tour-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(37,99,235,0.05);
            transition: background 0.15s ease;
        }
        .upcoming-tour-item:last-child { border-bottom: none; }
        .upcoming-tour-item:hover { background: rgba(37,99,235,0.02); }
        .ut-date-box {
            width: 50px; height: 50px;
            background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(37,99,235,0.12));
            border: 1px solid rgba(37,99,235,0.12);
            border-radius: 6px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .ut-day { font-size: 1.1rem; font-weight: 800; color: var(--blue); line-height: 1; }
        .ut-month { font-size: 0.6rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
        .ut-details { flex: 1; min-width: 0; }
        .ut-prop { font-size: 0.88rem; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 0.2rem; }
        .ut-meta { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .ut-meta span { font-size: 0.74rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.25rem; }
        .ut-meta span i { font-size: 0.68rem; }
        .ut-type-badge { padding: 0.15rem 0.5rem; border-radius: 3px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .ut-type-private { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .ut-type-public { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }

        /* ===== LATEST SALE ===== */
        .latest-sale-card {
            background: linear-gradient(135deg, rgba(212,175,55,0.03), rgba(37,99,235,0.02));
            border: 1px solid rgba(212,175,55,0.15);
            border-radius: 5px;
            overflow: hidden;
        }
        .ls-top {
            display: flex; align-items: center; gap: 0.85rem;
            padding: 1rem 1.15rem;
            border-bottom: 1px solid rgba(212,175,55,0.1);
        }
        .ls-icon {
            width: 42px; height: 42px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.18));
            color: var(--gold-dark); font-size: 1.1rem;
            border: 1px solid rgba(212,175,55,0.2);
            flex-shrink: 0;
        }
        .ls-info { flex: 1; min-width: 0; }
        .ls-address { font-size: 0.9rem; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ls-type { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.1rem; }
        .ls-bottom {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 0; /* border will act as separator */
        }
        .ls-detail {
            padding: 0.85rem 1rem;
            display: flex; flex-direction: column; gap: 0.15rem;
            border-right: 1px solid rgba(212,175,55,0.1);
        }
        .ls-detail:last-child { border-right: none; }
        .ls-detail-label { font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); }
        .ls-detail-value { font-size: 0.84rem; font-weight: 700; color: var(--text-primary); }

        /* ===== BIO BLOCK ===== */
        .bio-block {
            background: linear-gradient(135deg, rgba(37,99,235,0.02), rgba(212,175,55,0.02));
            border-left: 3px solid var(--gold-dark);
            border-radius: 0 5px 5px 0;
            padding: 1.15rem 1.35rem;
            position: relative;
            max-height: 240px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(212,175,55,0.2) transparent;
        }
        .bio-block::-webkit-scrollbar { width: 4px; }
        .bio-block::-webkit-scrollbar-track { background: transparent; }
        .bio-block::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.2); border-radius: 10px; }
        .bio-block::before {
            content: '\201C'; position: absolute; top: -0.2rem; left: 0.85rem;
            font-size: 3.2rem; line-height: 1; color: rgba(212,175,55,0.15);
            font-family: Georgia, serif; pointer-events: none;
        }
        .bio-text {
            font-size: 0.93rem;
            line-height: 1.8;
            color: #475569;
            padding-left: 0.5rem;
            text-align: justify;
            margin: 0;
        }

        /* ===== SIDEBAR CARDS ===== */
        .sidebar-card {
            background: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 6px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            position: relative;
            overflow: hidden;
        }
        .sidebar-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--gold) 0%, var(--blue) 100%);
            opacity: 0.4;
        }
        .sidebar-title {
            font-size: 0.88rem; font-weight: 700; color: var(--text-primary);
            margin-bottom: 1.15rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .sidebar-title i { color: var(--blue); font-size: 1rem; }
        .sidebar-info-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.7rem 0;
            border-bottom: 1px solid rgba(37,99,235,0.05);
        }
        .sidebar-info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .sidebar-info-label { font-size: 0.84rem; color: var(--text-secondary); font-weight: 500; }
        .sidebar-info-value { font-size: 0.88rem; color: var(--text-primary); font-weight: 700; }

        .sidebar-contact-item {
            display: flex; align-items: center; gap: 0.85rem;
            padding: 0.85rem;
            background: rgba(37,99,235,0.02);
            border: 1px solid rgba(37,99,235,0.06);
            border-radius: 5px;
            margin-bottom: 0.65rem;
            text-decoration: none; color: inherit;
            transition: all 0.2s ease;
        }
        .sidebar-contact-item:last-child { margin-bottom: 0; }
        .sidebar-contact-item:hover { background: rgba(37,99,235,0.05); border-color: rgba(37,99,235,0.15); color: inherit; }
        .sidebar-contact-icon {
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            background: rgba(37,99,235,0.08); border: 1px solid rgba(37,99,235,0.12);
            border-radius: 5px; flex-shrink: 0;
        }
        .sidebar-contact-icon i { font-size: 1rem; color: var(--blue); }
        .sidebar-contact-text { flex: 1; min-width: 0; }
        .sidebar-contact-label { font-size: 0.65rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin-bottom: 1px; }
        .sidebar-contact-value { font-size: 0.88rem; color: var(--text-primary); font-weight: 600; word-break: break-all; }

        /* ===== SECTION DIVIDER ===== */
        .section-divider {
            display: flex; align-items: center; gap: 0.65rem;
            margin: 0.5rem 0 1.5rem; font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px; color: var(--text-secondary);
        }
        .section-divider::before,
        .section-divider::after { content: ''; flex: 1; height: 1px; background: rgba(37,99,235,0.1); }
        .section-divider span {
            display: inline-flex; align-items: center; gap: 0.4rem;
            white-space: nowrap; padding: 0.3rem 0.85rem;
            background: rgba(37,99,235,0.03);
            border: 1px solid rgba(37,99,235,0.08);
            border-radius: 20px;
        }

        /* ===== COMMISSION SECTION ===== */
        .comm-kpi-grid {
            display: grid; grid-template-columns: repeat(5, 1fr);
            gap: 1rem; margin-bottom: 1.75rem;
        }
        @media (max-width: 1200px) { .comm-kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px)  { .comm-kpi-grid { grid-template-columns: repeat(2, 1fr); } }

        /* Chart card */
        .comm-chart-card {
            background: var(--card-bg); border: 1px solid var(--border-light);
            border-radius: 6px; padding: 1.5rem; margin-bottom: 1.75rem;
            position: relative; overflow: hidden;
        }
        .comm-chart-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--gold), var(--blue), var(--gold)); opacity: 0.4; }
        .comm-chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem; }
        .comm-chart-header h3 { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin: 0; }
        .comm-chart-legend { display: flex; gap: 1.25rem; font-size: 0.8rem; }
        .comm-chart-legend span { display: flex; align-items: center; gap: 0.4rem; color: var(--text-secondary); }
        .comm-legend-dot { width: 10px; height: 10px; border-radius: 2px; flex-shrink: 0; }
        .comm-legend-dot.earned { background: var(--gold-dark); }
        .comm-legend-dot.paid { background: #16a34a; }
        .comm-chart-bars { display: flex; align-items: flex-end; gap: 6px; height: 160px; padding-bottom: 2rem; position: relative; }
        .comm-bar-group { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; }
        .comm-bar-wrapper { flex: 1; width: 100%; display: flex; align-items: flex-end; justify-content: center; gap: 3px; }
        .comm-bar { width: 45%; max-width: 26px; border-radius: 3px 3px 0 0; min-height: 3px; transition: all 0.4s ease; position: relative; cursor: pointer; }
        .comm-bar:hover { filter: brightness(1.2); }
        .comm-bar.earned-bar { background: linear-gradient(180deg, var(--gold), var(--gold-dark)); }
        .comm-bar.paid-bar { background: linear-gradient(180deg, #4ade80, #16a34a); }
        .comm-bar-tooltip { display: none; position: absolute; bottom: calc(100% + 5px); left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; border-radius: 4px; padding: 0.3rem 0.55rem; font-size: 0.68rem; white-space: nowrap; z-index: 10; pointer-events: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .comm-bar:hover .comm-bar-tooltip { display: block; }
        .comm-bar-label { font-size: 0.62rem; color: var(--text-secondary); margin-top: 0.4rem; text-align: center; white-space: nowrap; }
        .comm-no-data { display: flex; align-items: center; justify-content: center; height: 160px; color: var(--text-secondary); font-size: 0.88rem; gap: 0.5rem; }

        /* Commission table card */
        .comm-table-card {
            background: var(--card-bg); border: 1px solid var(--border-light);
            border-radius: 6px; overflow: hidden; position: relative;
        }
        .comm-table-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--gold), var(--blue), var(--gold)); opacity: 0.4; }
        .comm-table-header { display: flex; justify-content: space-between; align-items: center; padding: 1.1rem 1.5rem; border-bottom: 1px solid rgba(37,99,235,0.06); }
        .comm-table-header h3 { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin: 0; }
        .comm-result-count { font-size: 0.8rem; color: var(--text-secondary); }
        .comm-filter-bar { display: flex; align-items: center; gap: 0.85rem; padding: 0.85rem 1.5rem; border-bottom: 1px solid rgba(37,99,235,0.04); flex-wrap: wrap; }
        .comm-search-box { flex: 1; min-width: 180px; position: relative; }
        .comm-search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); font-size: 0.8rem; }
        .comm-search-box input { width: 100%; padding: 0.55rem 0.85rem 0.55rem 2.25rem; background: #fff; border: 1px solid rgba(37,99,235,0.12); border-radius: 4px; color: var(--text-primary); font-size: 0.85rem; font-family: inherit; transition: border-color 0.2s, box-shadow 0.2s; }
        .comm-search-box input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); }
        .comm-filter-group { display: flex; gap: 0.3rem; flex-wrap: wrap; }
        .comm-filter-btn { padding: 0.45rem 0.9rem; border: 1px solid rgba(37,99,235,0.12); border-radius: 4px; background: transparent; color: var(--text-secondary); font-size: 0.78rem; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
        .comm-filter-btn:hover { background: rgba(37,99,235,0.04); color: var(--blue); border-color: rgba(37,99,235,0.25); }
        .comm-filter-btn.active { background: rgba(37,99,235,0.06); color: var(--blue); border-color: rgba(37,99,235,0.25); }
        .comm-filter-btn .cbadge { font-size: 0.62rem; background: rgba(37,99,235,0.06); color: var(--blue); padding: 0.1rem 0.4rem; border-radius: 10px; margin-left: 0.3rem; }
        .comm-tbl { width: 100%; border-collapse: collapse; }
        .comm-tbl thead th { padding: 0.8rem 1rem; text-align: left; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-secondary); border-bottom: 1px solid rgba(37,99,235,0.06); background: rgba(37,99,235,0.015); white-space: nowrap; }
        .comm-tbl tbody tr { border-bottom: 1px solid rgba(0,0,0,0.03); transition: background 0.15s; }
        .comm-tbl tbody tr:hover { background: rgba(37,99,235,0.02); }
        .comm-tbl tbody tr:last-child { border-bottom: none; }
        .comm-tbl tbody td { padding: 0.9rem 1rem; font-size: 0.875rem; color: var(--text-primary); vertical-align: middle; }
        .comm-prop-cell { display: flex; align-items: center; gap: 0.75rem; }
        .comm-prop-icon { width: 36px; height: 36px; border-radius: 5px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; background: rgba(37,99,235,0.06); color: var(--blue); border: 1px solid rgba(37,99,235,0.1); }
        .comm-prop-addr { font-weight: 600; font-size: 0.85rem; color: var(--text-primary); line-height: 1.3; }
        .comm-prop-type { font-size: 0.71rem; color: var(--text-secondary); margin-top: 0.1rem; }
        .comm-buyer-name { font-weight: 600; font-size: 0.85rem; }
        .comm-buyer-email { font-size: 0.71rem; color: var(--text-secondary); margin-top: 0.1rem; }
        .comm-sale-price { font-weight: 700; color: var(--text-primary); font-size: 0.9rem; }
        .comm-amount { font-weight: 800; color: var(--gold-dark); font-size: 0.95rem; }
        .comm-rate-label { font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.1rem; }
        .comm-status-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.65rem; border-radius: 3px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .cs-paid { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .cs-calculated { background: rgba(212,175,55,0.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.18); }
        .cs-pending { background: rgba(245,158,11,0.08); color: #d97706; border: 1px solid rgba(245,158,11,0.18); }
        .cs-cancelled { background: rgba(239,68,68,0.08); color: #dc2626; border: 1px solid rgba(239,68,68,0.18); }
        .comm-date-primary { font-weight: 600; font-size: 0.85rem; }
        .comm-date-secondary { font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.1rem; }
        .comm-ref { font-family: 'Courier New', monospace; font-size: 0.75rem; background: rgba(37,99,235,0.04); padding: 0.2rem 0.45rem; border-radius: 3px; border: 1px solid rgba(37,99,235,0.08); color: var(--text-secondary); }
        .comm-empty { text-align: center; padding: 3rem 1rem; }
        .comm-empty-icon { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 1rem; background: linear-gradient(135deg, rgba(212,175,55,0.06), rgba(37,99,235,0.04)); border: 1px solid rgba(37,99,235,0.08); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--text-secondary); }
        .comm-empty h4 { font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.4rem; }
        .comm-empty p { font-size: 0.85rem; color: var(--text-secondary); max-width: 340px; margin: 0 auto; }
        @media (max-width: 768px) { .comm-table-responsive { overflow-x: auto; } .comm-tbl { min-width: 820px; } }

        /* ===== PAGINATION ===== */
        .comm-pagination {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.85rem 1.5rem;
            border-top: 1px solid rgba(37,99,235,0.06);
            flex-wrap: wrap; gap: 0.65rem;
        }
        .comm-page-info { font-size: 0.8rem; color: var(--text-secondary); font-weight: 500; }
        .comm-page-info strong { color: var(--text-primary); font-weight: 700; }
        .comm-page-controls { display: flex; align-items: center; gap: 0.3rem; }
        .comm-page-btn {
            width: 32px; height: 32px; border: 1px solid rgba(37,99,235,0.12);
            border-radius: 4px; background: transparent; color: var(--text-secondary);
            font-size: 0.82rem; font-weight: 600; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s ease; font-family: inherit;
        }
        .comm-page-btn:hover:not(:disabled) { background: rgba(37,99,235,0.06); color: var(--blue); border-color: rgba(37,99,235,0.25); }
        .comm-page-btn.active { background: linear-gradient(135deg, var(--blue-dark), var(--blue)); color: #fff; border-color: var(--blue); }
        .comm-page-btn:disabled { opacity: 0.35; cursor: not-allowed; }
        .comm-page-btn.arrow { font-size: 0.9rem; }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 2rem 1rem; color: var(--text-secondary); }
        .empty-state i { font-size: 1.75rem; opacity: 0.25; display: block; margin-bottom: 0.5rem; }

        /* ===== EDIT PROFILE OVERLAY ===== */
        .edit-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(5px);
            z-index: 9999; display: none;
            justify-content: center; align-items: flex-start;
            padding: 2rem; overflow-y: auto;
        }
        .edit-overlay.active { display: flex; }
        .edit-modal {
            background: var(--card-bg);
            border: 1px solid rgba(37,99,235,0.12);
            border-radius: 6px; width: 100%; max-width: 880px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.2);
            animation: editSlideIn 0.3s ease;
            position: relative; overflow: hidden;
        }
        @keyframes editSlideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .edit-modal-header {
            padding: 1.25rem 1.75rem;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid rgba(37,99,235,0.06);
            position: relative;
        }
        .edit-modal-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--gold), var(--blue), var(--gold)); }
        .edit-modal-header h3 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .edit-modal-header h3 i { color: var(--blue); }
        .edit-modal-close {
            width: 32px; height: 32px; border: 1px solid rgba(0,0,0,0.08);
            border-radius: 4px; background: transparent; color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease; font-size: 1.1rem;
        }
        .edit-modal-close:hover { background: rgba(239,68,68,0.06); border-color: rgba(239,68,68,0.2); color: #dc2626; }
        .edit-modal-body { padding: 1.75rem; max-height: calc(100vh - 200px); overflow-y: auto; }
        .edit-section-title {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: var(--blue);
            margin-bottom: 1rem; padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(37,99,235,0.06);
            display: flex; align-items: center; gap: 0.4rem;
        }
        .edit-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; }
        .edit-form-grid .full-width { grid-column: 1 / -1; }
        .edit-form-group label {
            display: block; font-size: 0.73rem; font-weight: 600;
            color: var(--text-secondary); margin-bottom: 0.35rem;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .edit-form-group label .required { color: #dc2626; }
        .edit-form-group input,
        .edit-form-group textarea,
        .edit-form-group select {
            width: 100%; padding: 0.6rem 0.85rem;
            font-size: 0.9rem; font-family: inherit;
            border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;
            background: #fff; color: var(--text-primary);
            transition: all 0.2s ease;
        }
        .edit-form-group input:focus,
        .edit-form-group textarea:focus,
        .edit-form-group select:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); }
        .edit-form-group textarea { resize: vertical; min-height: 90px; }

        /* Specialization chips in edit */
        .spec-chips-wrap {
            display: flex; flex-wrap: wrap; gap: 0.5rem; padding: 0.75rem;
            background: rgba(37,99,235,0.015); border: 1px solid rgba(0,0,0,0.1);
            border-radius: 4px; min-height: 54px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .spec-chips-wrap:focus-within { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); }
        .spec-chip input[type="checkbox"] { display: none; }
        .spec-chip label {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.35rem 0.8rem; font-size: 0.8rem; font-weight: 600;
            border: 1px solid rgba(37,99,235,0.15); border-radius: 20px;
            background: #fff; color: var(--text-secondary);
            cursor: pointer; transition: all 0.18s ease; user-select: none;
            text-transform: none; letter-spacing: 0; margin: 0;
        }
        .spec-chip label:hover { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.04); }
        .spec-chip input[type="checkbox"]:checked + label {
            background: linear-gradient(135deg, rgba(212,175,55,0.1), rgba(212,175,55,0.2));
            border-color: var(--gold-dark); color: var(--gold-dark);
            box-shadow: 0 2px 8px rgba(212,175,55,0.12);
        }
        .spec-chip input[type="checkbox"]:checked + label::before { content: '\2713'; font-size: 0.7rem; font-weight: 800; }
        .spec-none-msg { font-size: 0.78rem; color: var(--text-secondary); font-style: italic; display: none; }
        .spec-none-msg.visible { display: block; }
        .spec-counter { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.73rem; font-weight: 600; color: var(--text-secondary); margin-top: 0.4rem; }
        .spec-counter .count { color: var(--blue); font-weight: 700; }

        .edit-avatar-upload { display: flex; align-items: center; gap: 1.25rem; padding: 1rem; background: rgba(37,99,235,0.02); border: 1px dashed rgba(37,99,235,0.18); border-radius: 5px; }
        .edit-avatar-preview { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.08); flex-shrink: 0; }
        .edit-avatar-info { flex: 1; }
        .edit-avatar-info .upload-label { display: inline-flex; align-items: center; gap: 0.4rem; background: linear-gradient(135deg, var(--blue-dark), var(--blue)); color: #fff; font-size: 0.78rem; font-weight: 600; padding: 0.45rem 0.9rem; border-radius: 4px; cursor: pointer; transition: all 0.2s ease; }
        .edit-avatar-info .upload-label:hover { background: linear-gradient(135deg, var(--blue), var(--blue-light)); }
        .edit-avatar-info .upload-hint { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.35rem; }
        .edit-avatar-info input[type="file"] { display: none; }
        .edit-modal-footer { padding: 1.25rem 1.75rem; border-top: 1px solid rgba(37,99,235,0.06); display: flex; justify-content: flex-end; gap: 0.75rem; }
        .btn-cancel-edit { background: transparent; border: 1px solid rgba(0,0,0,0.12); color: var(--text-secondary); font-weight: 600; font-size: 0.85rem; padding: 0.6rem 1.25rem; border-radius: 4px; cursor: pointer; transition: all 0.2s ease; }
        .btn-cancel-edit:hover { border-color: rgba(0,0,0,0.25); color: var(--text-primary); }
        .btn-save-edit { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; border: none; font-weight: 700; font-size: 0.85rem; padding: 0.6rem 1.5rem; border-radius: 4px; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-save-edit:hover { background: linear-gradient(135deg, var(--gold), var(--gold-light)); transform: translateY(-1px); box-shadow: 0 4px 15px rgba(212,175,55,0.25); }
        .btn-save-edit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }

        @media (max-width: 768px) {
            .edit-overlay { padding: 1rem; }
            .edit-modal-body { padding: 1.25rem; }
            .edit-form-grid { grid-template-columns: 1fr; }
            .edit-modal-header, .edit-modal-footer { padding: 1rem 1.25rem; }
            .profile-hero-body { padding: 0 1.25rem 1.5rem; }
            .profile-hero-layout { flex-direction: column; align-items: center; text-align: center; gap: 1rem; }
            .profile-hero-meta { padding-top: 0; }
            .profile-hero-badges { justify-content: center; }
            .profile-hero-actions { justify-content: center; }
        }
        @media (max-width: 576px) {
            .edit-overlay { padding: 0.5rem; }
            .edit-avatar-upload { flex-direction: column; text-align: center; }
            .profile-hero-avatar { width: 90px; height: 90px; margin-top: -45px; }
        }

        /* Toast */
        .profile-toast {
            position: fixed; top: 1.5rem; right: 1.5rem; z-index: 10001;
            padding: 0.85rem 1.25rem; border-radius: 4px;
            font-size: 0.88rem; font-weight: 600;
            display: flex; align-items: center; gap: 0.5rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .profile-toast.show { transform: translateX(0); }
        .profile-toast.success { background: #16a34a; color: #fff; }
        .profile-toast.error { background: #dc2626; color: #fff; }

        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .spin { animation: spin 1s linear infinite; display: inline-block; }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Client-Side Rendering (CSR) Pattern
           Matches: admin_profile.php
           ================================================================ */
        @keyframes sk-shimmer { 0% { background-position: -800px 0; } 100% { background-position: 800px 0; } }
        @keyframes sk-shimmer-dark { 0% { background-position: -800px 0; } 100% { background-position: 800px 0; } }
        .sk-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
            background-size: 800px 100%;
            animation: sk-shimmer 1.4s ease-in-out infinite;
            border-radius: 4px;
        }
        .sk-shimmer-dark {
            background: linear-gradient(90deg, #2d3748 25%, #374151 50%, #2d3748 75%);
            background-size: 800px 100%;
            animation: sk-shimmer-dark 1.4s ease-in-out infinite;
            border-radius: 4px;
        }
        #page-content { display: none; }

        .sk-profile-hero { background:#fff; border-radius:6px; overflow:hidden; margin-bottom:1.75rem; border:1px solid rgba(37,99,235,0.08); }
        .sk-hero-cover { height:180px; width:100%; display:block; }
        .sk-hero-body { padding:0 2.5rem 1.75rem; }
        .sk-hero-layout { display:flex; align-items:flex-start; gap:2rem; flex-wrap:wrap; }
        .sk-stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem; }
        .sk-stat-card { background:#fff; border-radius:6px; border:1px solid rgba(37,99,235,0.08); padding:1.25rem 1.35rem; display:flex; flex-direction:column; gap:0.6rem; }
        .sk-stat-icon { width:42px; height:42px; border-radius:6px; flex-shrink:0; }
        .sk-content-card { background:#fff; border-radius:6px; border:1px solid rgba(37,99,235,0.08); overflow:hidden; margin-bottom:1.5rem; }
        .sk-card-header-bar { padding:1.15rem 1.5rem; border-bottom:1px solid rgba(37,99,235,0.06); display:flex; align-items:center; gap:0.5rem; }
        .sk-card-body-inner { padding:1.5rem; }
        .sk-info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; }
        .sk-line { display:block; border-radius:4px; }
        @media (max-width:992px) { .sk-stats-row { grid-template-columns:repeat(2,1fr); } .sk-info-grid { grid-template-columns:repeat(2,1fr); } }
        @media (max-width:480px) { .sk-stats-row { grid-template-columns:1fr 1fr; gap:0.65rem; } }
    </style>
</head>
<body>
    <?php $active_page = 'admin_profile.php'; include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">

        <noscript><style>
            #sk-screen    { display: none !important; }
            #page-content { display: block !important; opacity: 1 !important; }
        </style></noscript>

        <!-- ══════════════════════════════════════════════════════════
             SKELETON SCREEN — visible on first paint
        ══════════════════════════════════════════════════════════ -->
        <div id="sk-screen" role="presentation" aria-hidden="true">

            <!-- Profile Hero Skeleton -->
            <div class="sk-profile-hero">
                <div class="sk-hero-cover sk-shimmer-dark"></div>
                <div class="sk-hero-body">
                    <div class="sk-hero-layout">
                        <!-- Avatar circle overlaps cover -->
                        <div class="sk-shimmer" style="width:130px;height:130px;border-radius:50%;border:5px solid #fff;margin-top:-65px;flex-shrink:0;"></div>
                        <!-- Name + role + email + badges -->
                        <div style="flex:1;min-width:250px;padding-top:0.75rem;display:flex;flex-direction:column;gap:0.5rem;">
                            <div class="sk-line sk-shimmer" style="width:230px;height:26px;"></div>
                            <div class="sk-shimmer" style="width:175px;height:22px;border-radius:3px;"></div>
                            <div class="sk-line sk-shimmer" style="width:190px;height:14px;"></div>
                            <div style="display:flex;gap:0.5rem;margin-top:0.1rem;">
                                <div class="sk-shimmer" style="width:80px;height:22px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:105px;height:22px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:120px;height:22px;border-radius:3px;"></div>
                            </div>
                        </div>
                        <!-- Action buttons -->
                        <div style="display:flex;gap:0.75rem;padding-top:1rem;flex-shrink:0;">
                            <div class="sk-shimmer" style="width:125px;height:38px;border-radius:4px;"></div>
                            <div class="sk-shimmer" style="width:105px;height:38px;border-radius:4px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4 Quick Stats -->
            <div class="sk-stats-row">
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="sk-stat-card">
                    <div class="sk-stat-icon sk-shimmer"></div>
                    <div class="sk-line sk-shimmer" style="width:80px;height:11px;"></div>
                    <div class="sk-line sk-shimmer" style="width:50px;height:26px;"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Content Card 1: Quick Info (9-item info grid) -->
            <div class="sk-content-card">
                <div class="sk-card-header-bar">
                    <div class="sk-shimmer" style="width:18px;height:18px;border-radius:3px;"></div>
                    <div class="sk-line sk-shimmer" style="width:90px;height:15px;"></div>
                </div>
                <div class="sk-card-body-inner">
                    <div class="sk-info-grid">
                        <?php for ($i = 0; $i < 9; $i++): ?>
                        <div style="display:flex;flex-direction:column;gap:0.4rem;">
                            <div class="sk-line sk-shimmer" style="width:70px;height:11px;"></div>
                            <div class="sk-line sk-shimmer" style="width:<?php echo [80,100,60,90,45,70,90,100,110][$i]; ?>px;height:16px;"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Content Card 2: Platform Activity -->
            <div class="sk-content-card">
                <div class="sk-card-header-bar">
                    <div class="sk-shimmer" style="width:18px;height:18px;border-radius:3px;"></div>
                    <div class="sk-line sk-shimmer" style="width:140px;height:15px;"></div>
                </div>
                <div class="sk-card-body-inner">
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="sk-shimmer" style="height:72px;border-radius:4px;"></div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Content Card 3: Commission Summary -->
            <div class="sk-content-card">
                <div class="sk-card-header-bar">
                    <div class="sk-shimmer" style="width:18px;height:18px;border-radius:3px;"></div>
                    <div class="sk-line sk-shimmer" style="width:180px;height:15px;"></div>
                </div>
                <div class="sk-card-body-inner">
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1rem;">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                        <div style="display:flex;flex-direction:column;gap:0.4rem;">
                            <div class="sk-line sk-shimmer" style="width:60px;height:11px;"></div>
                            <div class="sk-line sk-shimmer" style="width:80px;height:22px;"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="sk-shimmer" style="height:200px;width:100%;border-radius:4px;"></div>
                </div>
            </div>

        </div><!-- /#sk-screen -->

        <div id="page-content">

        <!-- Profile Hero Card -->
        <div class="profile-hero">
            <div class="profile-hero-cover">
                <div class="cover-dot-grid"></div>
                <div class="cover-glow-1"></div>
                <div class="cover-glow-2"></div>
                <div class="cover-line"></div>
            </div>
            <div class="profile-hero-body">
                <div class="profile-hero-layout">
                    <img src="<?php echo $avatar_src; ?>" alt="Profile Photo" class="profile-hero-avatar">
                    <div class="profile-hero-meta">
                        <h2 class="profile-hero-name"><?php echo $full_name; ?></h2>
                        <span class="profile-hero-role"><i class="bi bi-shield-check"></i> System Administrator</span>
                        <p class="profile-hero-email"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($admin['email'] ?? ''); ?></p>
                        <div class="profile-hero-badges">
                            <?php if (!empty($admin['is_active'])): ?>
                                <span class="badge-status badge-active"><i class="bi bi-circle-fill" style="font-size:0.45rem"></i> Active</span>
                            <?php else: ?>
                                <span class="badge-status badge-inactive"><i class="bi bi-circle-fill" style="font-size:0.45rem"></i> Inactive</span>
                            <?php endif; ?>
                            <?php if (!empty($admin['two_factor_enabled'])): ?>
                                <span class="badge-status badge-2fa"><i class="bi bi-shield-lock"></i> 2FA Enabled</span>
                            <?php else: ?>
                                <span class="badge-status badge-2fa-off"><i class="bi bi-shield-exclamation"></i> 2FA Disabled</span>
                            <?php endif; ?>
                            <?php if (!empty($admin['profile_completed'])): ?>
                                <span class="badge-status badge-active"><i class="bi bi-check-circle-fill" style="font-size:0.5rem"></i> Profile Complete</span>
                            <?php else: ?>
                                <span class="badge-status badge-inactive"><i class="bi bi-exclamation-circle" style="font-size:0.5rem"></i> Incomplete</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="profile-hero-actions">
                        <button type="button" class="btn-gold" id="openEditProfile"><i class="bi bi-pencil-square"></i> Edit Profile</button>
                        <a href="admin_settings.php" class="btn-outline-admin"><i class="bi bi-gear"></i> Settings</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="bi bi-building"></i></div>
                <div class="stat-label">Total Properties</div>
                <div class="stat-value"><?php echo number_format($total_properties); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold"><i class="bi bi-person-badge"></i></div>
                <div class="stat-label">Active Agents</div>
                <div class="stat-value"><?php echo number_format($total_agents); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="bi bi-calendar-check"></i></div>
                <div class="stat-label">Tour Requests</div>
                <div class="stat-value"><?php echo number_format($total_tours); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon cyan"><i class="bi bi-check-circle"></i></div>
                <div class="stat-label">Finalized Sales</div>
                <div class="stat-value"><?php echo number_format($total_sales); ?></div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="profile-grid">

            <!-- LEFT COLUMN -->
            <div>

                <!-- Quick Info -->
                <div class="content-card">
                    <div class="card-header">
                        <i class="bi bi-info-circle-fill"></i>
                        <h3>Quick Info</h3>
                    </div>
                    <div class="card-body-inner">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-graph-up-arrow"></i> Experience</div>
                                <div class="info-value"><?php echo $experience_text; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-credit-card"></i> License</div>
                                <div class="info-value"><?php echo htmlspecialchars($admin['license_number'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-building"></i> Properties</div>
                                <div class="info-value"><?php echo number_format($total_properties); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-person-badge"></i> Active Agents</div>
                                <div class="info-value"><?php echo number_format($total_agents); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-calendar-check"></i> Tours</div>
                                <div class="info-value"><?php echo number_format($total_tours); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-check2-square"></i> Sales Closed</div>
                                <div class="info-value"><?php echo number_format($total_sales); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-calendar3"></i> Member Since</div>
                                <div class="info-value"><?php echo $member_since; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-patch-check"></i> Profile Status</div>
                                <div class="info-value">
                                    <?php if (!empty($admin['profile_completed'])): ?>
                                        <span class="badge-status badge-active"><i class="bi bi-check-circle-fill" style="font-size:0.45rem"></i> Complete</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-inactive"><i class="bi bi-exclamation-circle" style="font-size:0.45rem"></i> Incomplete</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-coin"></i> Commission Earned</div>
                                <div class="info-value">&#8369;<?php echo number_format($comm_earnings, 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- About / Bio -->
                <?php if (!empty($admin['bio'])): ?>
                <div class="content-card">
                    <div class="card-header">
                        <i class="bi bi-chat-quote-fill"></i>
                        <h3>About Me</h3>
                    </div>
                    <div class="card-body-inner">
                        <div class="bio-block">
                            <p class="bio-text"><?php echo nl2br(htmlspecialchars($admin['bio'])); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Account Information -->
                <div class="content-card">
                    <div class="card-header">
                        <i class="bi bi-person-vcard-fill"></i>
                        <h3>Account Information</h3>
                    </div>
                    <div class="card-body-inner">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-person"></i> Full Name</div>
                                <div class="info-value"><?php echo $full_name; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-at"></i> Username</div>
                                <div class="info-value"><?php echo htmlspecialchars($admin['username'] ?? ''); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-calendar3"></i> Date Registered</div>
                                <div class="info-value"><?php echo !empty($admin['date_registered']) ? date('F d, Y', strtotime($admin['date_registered'])) : 'N/A'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-envelope"></i> Email Address</div>
                                <div class="info-value"><a href="mailto:<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" style="color:inherit;text-decoration:none;" onmouseover="this.style.color='var(--blue)'" onmouseout="this.style.color='inherit'"><?php echo htmlspecialchars($admin['email'] ?? 'Not provided'); ?></a></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-phone"></i> Phone Number</div>
                                <div class="info-value"><?php if(!empty($admin['phone_number'])): ?><a href="tel:<?php echo htmlspecialchars($admin['phone_number']); ?>" style="color:inherit;text-decoration:none;" onmouseover="this.style.color='var(--blue)'" onmouseout="this.style.color='inherit'"><?php echo htmlspecialchars($admin['phone_number']); ?></a><?php else: ?>Not provided<?php endif; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-shield-check"></i> Account Status</div>
                                <div class="info-value">
                                    <?php if (!empty($admin['is_active'])): ?>
                                        <span class="badge-status badge-active"><i class="bi bi-circle-fill" style="font-size:0.45rem"></i> Active</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-inactive"><i class="bi bi-circle-fill" style="font-size:0.45rem"></i> Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-shield-lock"></i> 2FA Status</div>
                                <div class="info-value">
                                    <?php if (!empty($admin['two_factor_enabled'])): ?>
                                        <span class="badge-status badge-2fa"><i class="bi bi-shield-lock"></i> Enabled</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-2fa-off"><i class="bi bi-shield-exclamation"></i> Disabled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-clock-history"></i> Last Login</div>
                                <div class="info-value" style="font-size:0.875rem;"><?php echo $last_login_ts ? date('M d, Y h:i A', strtotime($last_login_ts)) : 'N/A'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-calendar2-check"></i> Member Since</div>
                                <div class="info-value"><?php echo $member_since; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Professional Details -->
                <div class="content-card">
                    <div class="card-header">
                        <i class="bi bi-briefcase-fill"></i>
                        <h3>Professional Details</h3>
                    </div>
                    <div class="card-body-inner">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-credit-card"></i> License Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($admin['license_number'] ?? 'Not set'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-graph-up-arrow"></i> Years of Experience</div>
                                <div class="info-value"><?php echo $experience_text; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-building"></i> Active Listings</div>
                                <div class="info-value"><?php echo number_format($total_properties); ?> Properties</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-check2-square"></i> Sales Closed</div>
                                <div class="info-value"><?php echo number_format($total_sales); ?> Transaction<?php echo $total_sales !== 1 ? 's' : ''; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-coin"></i> Total Commission</div>
                                <div class="info-value">&#8369;<?php echo number_format($comm_earnings, 2); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-percent"></i> Avg Commission Rate</div>
                                <div class="info-value"><?php echo number_format($comm_avgRate, 2); ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Specializations -->
                <?php if (!empty($current_specs)): ?>
                <div class="content-card">
                    <div class="card-header">
                        <i class="bi bi-award-fill"></i>
                        <h3>Specializations</h3>
                    </div>
                    <div class="card-body-inner">
                        <div class="spec-tags">
                            <?php foreach ($current_specs as $spec): ?>
                                <span class="spec-tag"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($spec); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Platform Activity -->
                <div class="content-card">
                    <div class="card-header">
                        <i class="bi bi-activity"></i>
                        <h3>Platform Activity</h3>
                    </div>
                    <div class="card-body-inner">
                        <!-- Pending Actions Row -->
                        <div class="activity-pending-row">
                            <div class="activity-pending-item" style="--ap-color: #d97706;">
                                <div class="ap-icon"><i class="bi bi-calendar2-event"></i></div>
                                <div class="ap-info">
                                    <span class="ap-count"><?php echo $pending_tours; ?></span>
                                    <span class="ap-label">Pending Tours</span>
                                </div>
                            </div>
                            <div class="activity-pending-item" style="--ap-color: #2563eb;">
                                <div class="ap-icon"><i class="bi bi-house-check"></i></div>
                                <div class="ap-info">
                                    <span class="ap-count"><?php echo $pending_approvals; ?></span>
                                    <span class="ap-label">Property Approvals</span>
                                </div>
                            </div>
                            <div class="activity-pending-item" style="--ap-color: #7c3aed;">
                                <div class="ap-icon"><i class="bi bi-receipt-cutoff"></i></div>
                                <div class="ap-info">
                                    <span class="ap-count"><?php echo $pending_sales; ?></span>
                                    <span class="ap-label">Sale Verifications</span>
                                </div>
                            </div>
                            <div class="activity-pending-item" style="--ap-color: #16a34a;">
                                <div class="ap-icon"><i class="bi bi-check2-all"></i></div>
                                <div class="ap-info">
                                    <span class="ap-count"><?php echo $confirmed_tours; ?></span>
                                    <span class="ap-label">Confirmed Tours</span>
                                </div>
                            </div>
                        </div>

                        <!-- Property Breakdown -->
                        <div class="activity-section-label"><i class="bi bi-pie-chart-fill"></i> Property Breakdown</div>
                        <div class="property-breakdown">
                            <div class="pb-item">
                                <div class="pb-dot" style="background: #2563eb;"></div>
                                <span class="pb-label">For Sale</span>
                                <span class="pb-value"><?php echo $for_sale_count; ?></span>
                            </div>
                            <div class="pb-item">
                                <div class="pb-dot" style="background: #7c3aed;"></div>
                                <span class="pb-label">For Rent</span>
                                <span class="pb-value"><?php echo $for_rent_count; ?></span>
                            </div>
                            <div class="pb-item">
                                <div class="pb-dot" style="background: #16a34a;"></div>
                                <span class="pb-label">Sold</span>
                                <span class="pb-value"><?php echo $sold_count; ?></span>
                            </div>
                            <div class="pb-item">
                                <div class="pb-dot" style="background: #d97706;"></div>
                                <span class="pb-label">Pending Approval</span>
                                <span class="pb-value"><?php echo $pending_approvals; ?></span>
                            </div>
                        </div>
                        <?php if (!empty($property_types)): ?>
                        <!-- Property Types -->
                        <div class="activity-section-label" style="margin-top: 1.25rem;"><i class="bi bi-houses-fill"></i> Top Property Types</div>
                        <div class="property-types-list">
                            <?php
                                $pt_max = $property_types[0]['cnt'] ?? 1;
                                foreach ($property_types as $pt):
                                    $pct = $pt_max > 0 ? round(($pt['cnt'] / $pt_max) * 100) : 0;
                            ?>
                            <div class="pt-row">
                                <span class="pt-name"><?php echo htmlspecialchars($pt['PropertyType']); ?></span>
                                <div class="pt-bar-wrap">
                                    <div class="pt-bar" style="width: <?php echo $pct; ?>%;"></div>
                                </div>
                                <span class="pt-count"><?php echo $pt['cnt']; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Tours -->
                <?php if (!empty($upcoming_tours)): ?>
                <div class="content-card">
                    <div class="card-header">
                        <i class="bi bi-calendar-week-fill"></i>
                        <h3>Upcoming Tours</h3>
                        <span style="margin-left:auto; font-size:0.72rem; color:var(--text-secondary); font-weight:600;">Next 7 Days</span>
                    </div>
                    <div class="card-body-inner" style="padding: 0;">
                        <?php foreach ($upcoming_tours as $ut): ?>
                        <div class="upcoming-tour-item">
                            <div class="ut-date-box">
                                <span class="ut-day"><?php echo date('d', strtotime($ut['tour_date'])); ?></span>
                                <span class="ut-month"><?php echo date('M', strtotime($ut['tour_date'])); ?></span>
                            </div>
                            <div class="ut-details">
                                <div class="ut-prop"><?php echo htmlspecialchars(trim(($ut['StreetAddress'] ?? '') . ', ' . ($ut['City'] ?? ''))); ?></div>
                                <div class="ut-meta">
                                    <span><i class="bi bi-clock"></i> <?php echo date('g:i A', strtotime($ut['tour_time'])); ?></span>
                                    <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($ut['user_name']); ?></span>
                                    <span class="ut-type-badge ut-type-<?php echo $ut['tour_type']; ?>"><?php echo ucfirst($ut['tour_type']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Latest Sale -->
                <?php if (!empty($latest_sale)): ?>
                <div class="content-card">
                    <div class="card-header">
                        <i class="bi bi-trophy-fill" style="color: var(--gold-dark);"></i>
                        <h3>Latest Sale</h3>
                    </div>
                    <div class="card-body-inner">
                        <div class="latest-sale-card">
                            <div class="ls-top">
                                <div class="ls-icon"><i class="bi bi-house-check-fill"></i></div>
                                <div class="ls-info">
                                    <div class="ls-address"><?php echo htmlspecialchars(trim(($latest_sale['StreetAddress'] ?? '') . ', ' . ($latest_sale['City'] ?? ''))); ?></div>
                                    <div class="ls-type"><?php echo htmlspecialchars($latest_sale['PropertyType'] ?? 'Property'); ?></div>
                                </div>
                            </div>
                            <div class="ls-bottom">
                                <div class="ls-detail">
                                    <span class="ls-detail-label">Sale Price</span>
                                    <span class="ls-detail-value" style="color: var(--gold-dark); font-weight: 800;">&#8369;<?php echo number_format((float)$latest_sale['final_sale_price'], 2); ?></span>
                                </div>
                                <div class="ls-detail">
                                    <span class="ls-detail-label">Buyer</span>
                                    <span class="ls-detail-value"><?php echo htmlspecialchars($latest_sale['buyer_name'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="ls-detail">
                                    <span class="ls-detail-label">Date</span>
                                    <span class="ls-detail-value"><?php echo $latest_sale['sale_date'] ? date('M d, Y', strtotime($latest_sale['sale_date'])) : 'N/A'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

        </div>

        <!-- Commission Overview -->
        <div class="section-divider"><span><i class="bi bi-coin"></i> Commission Overview</span></div>

        <!-- Commission KPIs -->
        <div class="comm-kpi-grid">
            <div class="stat-card">
                <div class="stat-icon gold"><i class="bi bi-coin"></i></div>
                <div class="stat-label">Total Commission Earned</div>
                <div class="stat-value">&#8369;<?php echo number_format($comm_earnings, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
                <div class="stat-label">Paid Out</div>
                <div class="stat-value">&#8369;<?php echo number_format($comm_paid, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(245,158,11,0.08);color:#d97706;border:1px solid rgba(245,158,11,0.12);"><i class="bi bi-hourglass-split"></i></div>
                <div class="stat-label">Awaiting Payment</div>
                <div class="stat-value">&#8369;<?php echo number_format($comm_pending + $comm_calculated, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="stat-label">Sales Volume</div>
                <div class="stat-value">&#8369;<?php echo number_format($comm_salesVol, 0); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon cyan"><i class="bi bi-percent"></i></div>
                <div class="stat-label">Avg Commission Rate</div>
                <div class="stat-value"><?php echo number_format($comm_avgRate, 2); ?>%</div>
            </div>
        </div>

        <!-- Monthly Earnings Chart -->
        <div class="comm-chart-card">
            <div class="comm-chart-header">
                <h3><i class="bi bi-bar-chart-fill" style="color:var(--gold-dark);"></i> Monthly Commission (Last 12 Months)</h3>
                <div class="comm-chart-legend">
                    <span><span class="comm-legend-dot earned"></span> Earned</span>
                    <span><span class="comm-legend-dot paid"></span> Paid</span>
                </div>
            </div>
            <?php
                $comm_maxVal = max(1, max(array_column($comm_monthly, 'earned')));
                $comm_hasData = array_sum(array_column($comm_monthly, 'earned')) > 0;
            ?>
            <?php if ($comm_hasData): ?>
            <div class="comm-chart-bars">
                <?php foreach ($comm_monthly as $cm): ?>
                <div class="comm-bar-group">
                    <div class="comm-bar-wrapper">
                        <div class="comm-bar earned-bar" style="height:<?php echo max(2, ($cm['earned'] / $comm_maxVal) * 100); ?>%;">
                            <div class="comm-bar-tooltip">&#8369;<?php echo number_format($cm['earned'], 0); ?></div>
                        </div>
                        <div class="comm-bar paid-bar" style="height:<?php echo $cm['paid'] > 0 ? max(2, ($cm['paid'] / $comm_maxVal) * 100) : 0; ?>%;">
                            <?php if ($cm['paid'] > 0): ?>
                            <div class="comm-bar-tooltip">&#8369;<?php echo number_format($cm['paid'], 0); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="comm-bar-label"><?php echo $cm['short']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="comm-no-data">
                <i class="bi bi-bar-chart" style="font-size:1.3rem;opacity:0.3;"></i>
                No earnings data yet — chart will populate after your first commission is processed.
            </div>
            <?php endif; ?>
        </div>

        <!-- Commission Table -->
        <div class="comm-table-card">
            <div class="comm-table-header">
                <h3><i class="bi bi-receipt" style="color:var(--gold-dark);"></i> Commission Records</h3>
                <span class="comm-result-count" id="commResultCount"><?php echo count($comm_rows); ?> record<?php echo count($comm_rows) !== 1 ? 's' : ''; ?></span>
            </div>
            <?php if (!empty($comm_rows)): ?>
            <div class="comm-filter-bar">
                <div class="comm-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="commSearch" placeholder="Search by property, buyer, reference..." autocomplete="off">
                </div>
                <div class="comm-filter-group">
                    <button class="comm-filter-btn active" data-cfilter="all">All <span class="cbadge"><?php echo count($comm_rows); ?></span></button>
                    <button class="comm-filter-btn" data-cfilter="paid">Paid <span class="cbadge"><?php echo $comm_paidCount; ?></span></button>
                    <button class="comm-filter-btn" data-cfilter="calculated">Calculated <span class="cbadge"><?php echo $comm_calcCount; ?></span></button>
                    <button class="comm-filter-btn" data-cfilter="pending">Pending <span class="cbadge"><?php echo $comm_pendCount; ?></span></button>
                </div>
            </div>
            <div class="comm-table-responsive">
                <table class="comm-tbl">
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Buyer</th>
                            <th>Sale Date</th>
                            <th>Sale Price</th>
                            <th>Commission</th>
                            <th>Status</th>
                            <th>Paid On</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody id="commTableBody">
                        <?php foreach ($comm_rows as $ci => $cr):
                            $c_addr = trim(($cr['StreetAddress'] ?? '') . ', ' . ($cr['City'] ?? ''));
                            $c_addr = ($c_addr !== ',') ? $c_addr : 'Property #' . (int)$cr['property_id'];
                            $c_type = $cr['PropertyType'] ?? 'Property';
                            $c_status = strtolower($cr['status']);
                            $c_status_class = 'cs-pending';
                            $c_status_icon  = 'bi-clock';
                            if ($c_status === 'paid')       { $c_status_class = 'cs-paid';       $c_status_icon = 'bi-check-circle-fill'; }
                            elseif ($c_status === 'calculated') { $c_status_class = 'cs-calculated'; $c_status_icon = 'bi-calculator'; }
                            elseif ($c_status === 'cancelled') { $c_status_class = 'cs-cancelled';  $c_status_icon = 'bi-x-circle'; }
                            $ct_lower = strtolower($c_type);
                            $c_icon = 'bi-building';
                            if (str_contains($ct_lower, 'single') || str_contains($ct_lower, 'house')) $c_icon = 'bi-house-fill';
                            elseif (str_contains($ct_lower, 'condo') || str_contains($ct_lower, 'apartment')) $c_icon = 'bi-buildings';
                            elseif (str_contains($ct_lower, 'town')) $c_icon = 'bi-houses';
                            elseif (str_contains($ct_lower, 'land') || str_contains($ct_lower, 'lot')) $c_icon = 'bi-map';
                            elseif (str_contains($ct_lower, 'commercial')) $c_icon = 'bi-shop';
                            $c_search = strtolower($c_addr . ' ' . ($cr['buyer_name'] ?? '') . ' ' . $c_type . ' ' . ($cr['payment_reference'] ?? ''));
                        ?>
                        <tr class="comm-row"
                            data-cstatus="<?php echo $c_status; ?>"
                            data-csearch="<?php echo htmlspecialchars($c_search); ?>">
                            <td>
                                <div class="comm-prop-cell">
                                    <div class="comm-prop-icon"><i class="bi <?php echo $c_icon; ?>"></i></div>
                                    <div>
                                        <div class="comm-prop-addr"><?php echo htmlspecialchars($c_addr); ?></div>
                                        <div class="comm-prop-type"><?php echo htmlspecialchars($c_type);
                                            if (!empty($cr['Bedrooms'])) echo ' &middot; ' . $cr['Bedrooms'] . 'bd';
                                            if (!empty($cr['Bathrooms'])) echo '/' . rtrim(rtrim(number_format($cr['Bathrooms'], 1), '0'), '.') . 'ba';
                                        ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="comm-buyer-name"><?php echo htmlspecialchars($cr['buyer_name'] ?? '—'); ?></div>
                                <?php if (!empty($cr['buyer_email'])): ?>
                                <div class="comm-buyer-email"><i class="bi bi-envelope me-1" style="font-size:0.6rem;"></i><?php echo htmlspecialchars($cr['buyer_email']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="comm-date-primary"><?php echo $cr['sale_date'] ? date('M j, Y', strtotime($cr['sale_date'])) : '—'; ?></div>
                                <?php if (!empty($cr['calculated_at'])): ?>
                                <div class="comm-date-secondary">Processed <?php echo date('M j', strtotime($cr['calculated_at'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="comm-sale-price">&#8369;<?php echo number_format((float)$cr['final_sale_price'], 2); ?></span></td>
                            <td>
                                <div class="comm-amount">&#8369;<?php echo number_format((float)$cr['commission_amount'], 2); ?></div>
                                <div class="comm-rate-label"><?php echo number_format((float)$cr['commission_percentage'], 2); ?>% rate</div>
                            </td>
                            <td><span class="comm-status-badge <?php echo $c_status_class; ?>"><i class="bi <?php echo $c_status_icon; ?>" style="font-size:0.6rem;"></i> <?php echo ucfirst($c_status); ?></span></td>
                            <td>
                                <?php if (!empty($cr['paid_at'])): ?>
                                <div class="comm-date-primary"><?php echo date('M j, Y', strtotime($cr['paid_at'])); ?></div>
                                <?php else: ?>
                                <span style="color:var(--text-secondary);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($cr['payment_reference'])): ?>
                                <span class="comm-ref"><?php echo htmlspecialchars($cr['payment_reference']); ?></span>
                                <?php else: ?>
                                <span style="color:var(--text-secondary);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="comm-pagination" id="commPagination">
                <span class="comm-page-info" id="commPageInfo"></span>
                <div class="comm-page-controls" id="commPageControls"></div>
            </div>
            <?php else: ?>
            <div class="comm-empty">
                <div class="comm-empty-icon"><i class="bi bi-coin"></i></div>
                <h4>No Commission Records Yet</h4>
                <p>Commission records will appear here once property sales are finalized and commissions are processed.</p>
            </div>
            <?php endif; ?>
        </div>

        </div><!-- /#page-content -->
    </div><!-- /.admin-content -->

    <!-- Edit Profile Overlay -->
    <div class="edit-overlay" id="editProfileOverlay">
        <div class="edit-modal">
            <div class="edit-modal-header">
                <h3><i class="bi bi-pencil-square"></i> Edit Profile</h3>
                <button type="button" class="edit-modal-close" id="closeEditProfile"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="editProfileForm" enctype="multipart/form-data">
                <div class="edit-modal-body">
                    <!-- Profile Photo -->
                    <div class="edit-section-title"><i class="bi bi-camera"></i> Profile Photo</div>
                    <div class="edit-avatar-upload" style="margin-bottom: 1.5rem;">
                        <img src="<?php echo $avatar_src; ?>" alt="Avatar" class="edit-avatar-preview" id="editAvatarPreview">
                        <div class="edit-avatar-info">
                            <label class="upload-label" for="editProfilePicture">
                                <i class="bi bi-cloud-upload"></i> Change Photo
                            </label>
                            <input type="file" id="editProfilePicture" name="profile_picture" accept="image/jpeg,image/png,image/gif">
                            <div class="upload-hint">JPG, PNG or GIF. Max 5MB.</div>
                        </div>
                    </div>

                    <!-- Account Information -->
                    <div class="edit-section-title"><i class="bi bi-person-vcard"></i> Account Information</div>
                    <div class="edit-form-grid">
                        <div class="edit-form-group">
                            <label for="editFirstName">First Name <span class="required">*</span></label>
                            <input type="text" id="editFirstName" name="first_name" value="<?php echo htmlspecialchars($admin['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="edit-form-group">
                            <label for="editMiddleName">Middle Name</label>
                            <input type="text" id="editMiddleName" name="middle_name" value="<?php echo htmlspecialchars($admin['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="edit-form-group">
                            <label for="editLastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="editLastName" name="last_name" value="<?php echo htmlspecialchars($admin['last_name'] ?? ''); ?>" required>
                        </div>
                        <div class="edit-form-group">
                            <label for="editPhone">Phone Number</label>
                            <input type="text" id="editPhone" name="phone_number" value="<?php echo htmlspecialchars($admin['phone_number'] ?? ''); ?>" placeholder="e.g. +63 912 345 6789">
                        </div>
                        <div class="edit-form-group full-width">
                            <label for="editEmail">Email Address <span class="required">*</span></label>
                            <input type="email" id="editEmail" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <!-- Professional Details -->
                    <div class="edit-section-title"><i class="bi bi-briefcase"></i> Professional Details</div>
                    <div class="edit-form-grid">
                        <div class="edit-form-group">
                            <label for="editLicense">License Number <span class="required">*</span></label>
                            <input type="text" id="editLicense" name="license_number" value="<?php echo htmlspecialchars($admin['license_number'] ?? ''); ?>" required>
                        </div>
                        <div class="edit-form-group">
                            <label for="editExperience">Years of Experience <span class="required">*</span></label>
                            <input type="number" id="editExperience" name="years_experience" value="<?php echo htmlspecialchars($admin['years_experience'] ?? ''); ?>" min="0" max="60" required>
                        </div>
                        <div class="edit-form-group full-width">
                            <label>Specialization <span class="required">*</span></label>
                            <input type="hidden" id="editSpecializationHidden" name="specialization" value="<?php echo htmlspecialchars($admin['specialization'] ?? ''); ?>">
                            <div class="spec-chips-wrap" id="specChipsWrap">
                                <?php foreach ($all_specs as $spec): ?>
                                    <?php
                                        $spec_name = $spec['specialization_name'];
                                        $is_checked = in_array($spec_name, $current_specs);
                                        $chip_id = 'spec_' . $spec['specialization_id'];
                                    ?>
                                    <div class="spec-chip">
                                        <input type="checkbox" id="<?php echo $chip_id; ?>" value="<?php echo htmlspecialchars($spec_name); ?>"<?php echo $is_checked ? ' checked' : ''; ?> onchange="updateSpecHidden()">
                                        <label for="<?php echo $chip_id; ?>"><?php echo htmlspecialchars($spec_name); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mt-1">
                                <span class="spec-none-msg" id="specNoneMsg">Select at least one specialization.</span>
                                <span class="spec-counter"><i class="bi bi-check2-circle"></i> <span class="count" id="specCount"><?php echo count($current_specs); ?></span> selected</span>
                            </div>
                        </div>
                        <div class="edit-form-group full-width">
                            <label for="editBio">Professional Bio <span class="required">*</span></label>
                            <textarea id="editBio" name="bio" rows="4" required><?php echo htmlspecialchars($admin['bio'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="edit-modal-footer">
                    <button type="button" class="btn-cancel-edit" id="cancelEditProfile">Cancel</button>
                    <button type="submit" class="btn-save-edit" id="saveEditProfile">
                        <i class="bi bi-check-lg"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div class="profile-toast" id="profileToast"></div>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
        // ===== EDIT PROFILE =====
        const overlay = document.getElementById('editProfileOverlay');
        const editForm = document.getElementById('editProfileForm');
        const fileInput = document.getElementById('editProfilePicture');
        const avatarPreview = document.getElementById('editAvatarPreview');

        function openEditProfile() {
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeEditProfile() {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        document.getElementById('openEditProfile').addEventListener('click', openEditProfile);
        document.getElementById('closeEditProfile').addEventListener('click', closeEditProfile);
        document.getElementById('cancelEditProfile').addEventListener('click', closeEditProfile);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeEditProfile(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && overlay.classList.contains('active')) closeEditProfile(); });

        // Image preview
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) { avatarPreview.src = e.target.result; };
                reader.readAsDataURL(this.files[0]);
            }
        });

        // Toast
        function showToast(message, type) {
            const toast = document.getElementById('profileToast');
            toast.className = 'profile-toast ' + type;
            toast.innerHTML = '<i class="bi bi-' + (type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i> ' + message;
            toast.classList.add('show');
            setTimeout(function() { toast.classList.remove('show'); }, 4000);
        }

        // Specialization chips
        function updateSpecHidden() {
            const checked = document.querySelectorAll('#specChipsWrap .spec-chip input[type="checkbox"]:checked');
            const values = Array.from(checked).map(cb => cb.value);
            document.getElementById('editSpecializationHidden').value = values.join(', ');
            document.getElementById('specNoneMsg').classList.toggle('visible', values.length === 0);
            const countEl = document.getElementById('specCount');
            if (countEl) countEl.textContent = values.length;
        }

        // Form submit
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const specVal = document.getElementById('editSpecializationHidden').value.trim();
            if (!specVal) {
                document.getElementById('specNoneMsg').classList.add('visible');
                document.getElementById('specChipsWrap').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            const saveBtn = document.getElementById('saveEditProfile');
            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Saving...';

            fetch('save_admin_info.php', { method: 'POST', body: new FormData(editForm) })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast('Profile updated successfully!', 'success');
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    showToast(data.message || 'Failed to update profile.', 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalText;
                }
            })
            .catch(() => {
                showToast('An error occurred. Please try again.', 'error');
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalText;
            });
        });

        // ===== COMMISSION SEARCH, FILTER & PAGINATION =====
        (function() {
            const searchInput  = document.getElementById('commSearch');
            const filterBtns   = document.querySelectorAll('.comm-filter-btn');
            const allRows      = Array.from(document.querySelectorAll('.comm-row'));
            const resultEl     = document.getElementById('commResultCount');
            const pageInfo     = document.getElementById('commPageInfo');
            const pageControls = document.getElementById('commPageControls');
            const pagination   = document.getElementById('commPagination');
            const PER_PAGE     = 5;

            if (!allRows.length) return;

            let activeFilter = 'all';
            let currentPage  = 1;
            let filteredRows = [];

            function getFilteredRows() {
                const q = searchInput ? searchInput.value.toLowerCase().trim() : '';
                return allRows.filter(row => {
                    const matchStatus = activeFilter === 'all' || row.dataset.cstatus === activeFilter;
                    const matchSearch = !q || (row.dataset.csearch || '').includes(q);
                    return matchStatus && matchSearch;
                });
            }

            function renderPage() {
                filteredRows = getFilteredRows();
                const total      = filteredRows.length;
                const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
                if (currentPage > totalPages) currentPage = totalPages;

                const start = (currentPage - 1) * PER_PAGE;
                const end   = Math.min(start + PER_PAGE, total);

                // Show / hide rows
                allRows.forEach(r => r.style.display = 'none');
                filteredRows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

                // Result count
                if (resultEl) resultEl.textContent = total + ' record' + (total !== 1 ? 's' : '');

                // Page info
                if (pageInfo) {
                    if (total === 0) {
                        pageInfo.innerHTML = 'No records found';
                    } else {
                        pageInfo.innerHTML = 'Showing <strong>' + (start + 1) + '–' + end + '</strong> of <strong>' + total + '</strong> records';
                    }
                }

                // Pagination controls
                if (pageControls) {
                    pageControls.innerHTML = '';
                    if (totalPages <= 1) { if (pagination) pagination.style.display = total === 0 ? 'none' : 'flex'; return; }
                    if (pagination) pagination.style.display = 'flex';

                    // Prev button
                    const prev = document.createElement('button');
                    prev.className = 'comm-page-btn arrow';
                    prev.innerHTML = '<i class="bi bi-chevron-left"></i>';
                    prev.disabled = currentPage === 1;
                    prev.addEventListener('click', () => { currentPage--; renderPage(); });
                    pageControls.appendChild(prev);

                    // Page number buttons (smart: show max 5 around current)
                    const range = [];
                    const delta = 2;
                    for (let i = Math.max(1, currentPage - delta); i <= Math.min(totalPages, currentPage + delta); i++) range.push(i);
                    if (range[0] > 1) {
                        appendPageBtn(1);
                        if (range[0] > 2) appendEllipsis();
                    }
                    range.forEach(p => appendPageBtn(p));
                    if (range[range.length - 1] < totalPages) {
                        if (range[range.length - 1] < totalPages - 1) appendEllipsis();
                        appendPageBtn(totalPages);
                    }

                    // Next button
                    const next = document.createElement('button');
                    next.className = 'comm-page-btn arrow';
                    next.innerHTML = '<i class="bi bi-chevron-right"></i>';
                    next.disabled = currentPage === totalPages;
                    next.addEventListener('click', () => { currentPage++; renderPage(); });
                    pageControls.appendChild(next);
                }
            }

            function appendPageBtn(p) {
                const btn = document.createElement('button');
                btn.className = 'comm-page-btn' + (p === currentPage ? ' active' : '');
                btn.textContent = p;
                btn.addEventListener('click', () => { currentPage = p; renderPage(); });
                pageControls.appendChild(btn);
            }

            function appendEllipsis() {
                const span = document.createElement('span');
                span.textContent = '…';
                span.style.cssText = 'padding: 0 0.2rem; color: var(--text-secondary); font-size: 0.82rem; line-height: 32px;';
                pageControls.appendChild(span);
            }

            // Filter button clicks
            filterBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    filterBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    activeFilter = this.dataset.cfilter;
                    currentPage = 1;
                    renderPage();
                });
            });

            // Search input
            if (searchInput) {
                searchInput.addEventListener('input', () => { currentPage = 1; renderPage(); });
            }

            // Initial render
            renderPage();
        })();
    </script>

    <!-- ══════════════════════════════════════════════════════════
         SKELETON HYDRATION SCRIPT
         Waits for window 'load' (fonts + CSS ready) then
         cross-fades skeleton out and real content in.
    ══════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        'use strict';
        var MIN_SKELETON_MS = 400;
        var skeletonStart   = Date.now();
        var hydrated        = false;
        function hydrate() {
            if (hydrated) return; hydrated = true;
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');
            if (!sk || !pc) return;
            sk.style.transition = 'opacity 0.35s ease'; sk.style.opacity = '0';
            setTimeout(function () { sk.style.display = 'none'; }, 360);
            pc.style.opacity = '0'; pc.style.display = 'block';
            requestAnimationFrame(function () { pc.style.transition = 'opacity 0.4s ease'; pc.style.opacity = '1'; });
            setTimeout(function () { document.dispatchEvent(new CustomEvent('skeleton:hydrated')); }, 520);
        }
        function scheduleHydration() {
            var elapsed   = Date.now() - skeletonStart;
            var remaining = MIN_SKELETON_MS - elapsed;
            if (remaining <= 0) { hydrate(); } else { setTimeout(hydrate, remaining); }
        }
        window.addEventListener('load', scheduleHydration);
    }());
    </script>
</body>
</html>
