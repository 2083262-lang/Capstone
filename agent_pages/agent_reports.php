<?php
session_start();
include '../connection.php';
require_once __DIR__ . '/../config/session_timeout.php';
require_once __DIR__ . '/../config/paths.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}

$agent_account_id = $_SESSION['account_id'];

// Agent info
$stmt = $conn->prepare("SELECT ai.*, a.first_name, a.last_name, a.username, a.email, a.phone_number, a.date_registered
                         FROM agent_information ai JOIN accounts a ON ai.account_id = a.account_id WHERE ai.account_id = ?");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$agent_info = $stmt->get_result()->fetch_assoc();
$stmt->close();
$agent_name = htmlspecialchars(trim(($agent_info['first_name'] ?? '') . ' ' . ($agent_info['last_name'] ?? '')));
$agent_username = $agent_info['username'] ?? $agent_name;

// =============================================
// PROPERTY REPORT DATA
// =============================================
$property_report = [];
$prop_sql = "SELECT p.property_ID, p.StreetAddress, p.City, p.Barangay, p.Province,
    p.PropertyType, p.Bedrooms, p.Bathrooms, p.SquareFootage,
    p.ListingPrice, p.Status, p.approval_status, p.ViewsCount, p.Likes,
    p.ListingDate, p.sold_date
FROM property p
JOIN property_log pl ON p.property_ID = pl.property_id AND pl.action = 'CREATED'
WHERE pl.account_id = ?
ORDER BY p.ListingDate DESC";
$stmt = $conn->prepare($prop_sql);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$prop_result = $stmt->get_result();
while ($row = $prop_result->fetch_assoc()) { $property_report[] = $row; }
$stmt->close();

// =============================================
// SALES REPORT DATA
// =============================================
$sales_report = [];
$sales_sql = "SELECT fs.sale_id, fs.property_id, p.StreetAddress, p.City, p.PropertyType, p.ListingPrice AS listing_price,
    fs.buyer_name, fs.buyer_email, fs.final_sale_price, fs.sale_date, fs.finalized_at,
    CONCAT(admin_acc.first_name, ' ', admin_acc.last_name) AS finalized_by_name,
    ac.commission_amount, ac.commission_percentage, ac.status AS commission_status
FROM finalized_sales fs
JOIN property p ON fs.property_id = p.property_ID
LEFT JOIN accounts admin_acc ON fs.finalized_by = admin_acc.account_id
LEFT JOIN agent_commissions ac ON fs.sale_id = ac.sale_id
WHERE fs.agent_id = ?
ORDER BY fs.sale_date DESC";
$stmt = $conn->prepare($sales_sql);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$sales_result = $stmt->get_result();
while ($row = $sales_result->fetch_assoc()) { $sales_report[] = $row; }
$stmt->close();

// =============================================
// RENTAL REPORT DATA
// =============================================
$rental_report = [];
$rental_sql = "SELECT fr.rental_id, fr.property_id, p.StreetAddress, p.City, p.PropertyType,
    fr.tenant_name, fr.tenant_email, fr.monthly_rent, fr.security_deposit,
    fr.lease_start_date, fr.lease_end_date, fr.lease_term_months, fr.commission_rate,
    fr.lease_status, fr.finalized_at,
    (SELECT COUNT(*) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id AND rp.status = 'Confirmed') AS confirmed_payments,
    (SELECT COUNT(*) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id AND rp.status = 'Pending') AS pending_payments,
    (SELECT COALESCE(SUM(rp.payment_amount),0) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id AND rp.status = 'Confirmed') AS total_collected,
    (SELECT COALESCE(SUM(rc.commission_amount),0) FROM rental_commissions rc WHERE rc.rental_id = fr.rental_id) AS total_commission
FROM finalized_rentals fr
JOIN property p ON fr.property_id = p.property_ID
WHERE fr.agent_id = ?
ORDER BY fr.finalized_at DESC";
$stmt = $conn->prepare($rental_sql);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$rental_result = $stmt->get_result();
while ($row = $rental_result->fetch_assoc()) { $rental_report[] = $row; }
$stmt->close();

// =============================================
// TOUR REQUESTS DATA
// =============================================
$tour_report = [];
$tour_sql = "SELECT tr.tour_id, tr.user_name, tr.user_email, tr.user_phone,
    tr.tour_date, tr.tour_time, tr.tour_type, tr.request_status,
    tr.requested_at, tr.confirmed_at, tr.completed_at,
    p.property_ID, p.StreetAddress, p.City, p.PropertyType, p.ListingPrice
FROM tour_requests tr
JOIN property p ON tr.property_id = p.property_ID
WHERE tr.agent_account_id = ?
ORDER BY tr.requested_at DESC";
$stmt = $conn->prepare($tour_sql);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$tour_result = $stmt->get_result();
while ($row = $tour_result->fetch_assoc()) { $tour_report[] = $row; }
$stmt->close();

// =============================================
// COMMISSION DATA (Sales + Rental)
// =============================================
$commission_report = [];
// Sales commissions
$sc_sql = "SELECT ac.commission_id, 'Sale' AS type, ac.commission_amount, ac.commission_percentage,
    ac.status, fs.sale_date AS event_date, fs.final_sale_price AS transaction_value,
    p.StreetAddress, p.City, p.PropertyType, fs.buyer_name AS client_name
FROM agent_commissions ac
JOIN finalized_sales fs ON ac.sale_id = fs.sale_id
LEFT JOIN property p ON fs.property_id = p.property_ID
WHERE ac.agent_id = ?";
$stmt = $conn->prepare($sc_sql);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$sc_result = $stmt->get_result();
while ($row = $sc_result->fetch_assoc()) { $commission_report[] = $row; }
$stmt->close();

// Rental commissions
$rc_sql = "SELECT rc.commission_id, 'Rental' AS type, rc.commission_amount, rc.commission_percentage,
    'paid' AS status, rp.payment_date AS event_date, rp.payment_amount AS transaction_value,
    p.StreetAddress, p.City, p.PropertyType, fr.tenant_name AS client_name
FROM rental_commissions rc
JOIN rental_payments rp ON rc.payment_id = rp.payment_id
JOIN finalized_rentals fr ON rc.rental_id = fr.rental_id
LEFT JOIN property p ON fr.property_id = p.property_ID
WHERE rc.agent_id = ?";
$stmt = $conn->prepare($rc_sql);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$rc_result = $stmt->get_result();
while ($row = $rc_result->fetch_assoc()) { $commission_report[] = $row; }
$stmt->close();

// Sort commissions by date desc
usort($commission_report, function($a, $b) {
    return strcmp($b['event_date'] ?? '', $a['event_date'] ?? '');
});

// =============================================
// KPI SUMMARY DATA
// =============================================
$stmt = $conn->prepare("SELECT
    COUNT(*) as total_listings,
    COUNT(CASE WHEN p.approval_status='approved' AND p.Status NOT IN ('Sold','Pending Sold','Rented','Pending Rented') THEN 1 END) as active_listings,
    COUNT(CASE WHEN p.Status='Sold' THEN 1 END) as sold_count,
    COUNT(CASE WHEN p.Status='For Sale' AND p.approval_status='approved' THEN 1 END) as for_sale,
    COUNT(CASE WHEN p.Status='For Rent' AND p.approval_status='approved' THEN 1 END) as for_rent,
    COUNT(CASE WHEN p.approval_status='pending' THEN 1 END) as pending_approval,
    COALESCE(SUM(CASE WHEN p.approval_status='approved' THEN p.ViewsCount ELSE 0 END),0) as total_views,
    COALESCE(SUM(CASE WHEN p.approval_status='approved' THEN p.Likes ELSE 0 END),0) as total_likes
FROM property p
JOIN property_log pl ON p.property_ID = pl.property_id AND pl.action='CREATED'
WHERE pl.account_id = ?");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$prop_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total_sales, COALESCE(SUM(final_sale_price),0) as total_revenue FROM finalized_sales WHERE agent_id=?");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$sales_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(commission_amount),0) as total_commission FROM agent_commissions WHERE agent_id=?");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$comm_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT
    COUNT(CASE WHEN lease_status IN ('Active','Renewed') THEN 1 END) as active_leases,
    COALESCE(SUM(CASE WHEN lease_status IN ('Active','Renewed') THEN monthly_rent ELSE 0 END),0) as monthly_rental_income
FROM finalized_rentals WHERE agent_id=?");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$rental_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(rc.commission_amount),0) as total FROM rental_commissions rc WHERE rc.agent_id=?");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$rental_comm = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT
    COUNT(*) as total_tours,
    COUNT(CASE WHEN request_status='Pending' THEN 1 END) as pending_tours,
    COUNT(CASE WHEN request_status='Confirmed' THEN 1 END) as confirmed_tours,
    COUNT(CASE WHEN request_status='Completed' THEN 1 END) as completed_tours,
    COUNT(CASE WHEN request_status IN ('Cancelled','Rejected') THEN 1 END) as cancelled_tours
FROM tour_requests WHERE agent_account_id=?");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$tour_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Chart: Properties by type (agent only)
$prop_by_type = [];
$stmt = $conn->prepare("SELECT p.PropertyType, COUNT(*) as cnt FROM property p JOIN property_log pl ON p.property_ID=pl.property_id AND pl.action='CREATED' WHERE pl.account_id=? GROUP BY p.PropertyType ORDER BY cnt DESC");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $prop_by_type[$row['PropertyType']] = (int)$row['cnt']; }
$stmt->close();

// Chart: Sales by month (agent only)
$sales_by_month = [];
$stmt = $conn->prepare("SELECT DATE_FORMAT(sale_date,'%Y-%m') AS month_key, DATE_FORMAT(sale_date,'%b %Y') AS month_label, COUNT(*) AS cnt, SUM(final_sale_price) AS revenue FROM finalized_sales WHERE agent_id=? GROUP BY month_key ORDER BY month_key ASC");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $sales_by_month[] = $row; }
$stmt->close();

// Chart: Tour statuses (agent only)
$tour_statuses = [];
$stmt = $conn->prepare("SELECT request_status, COUNT(*) as cnt FROM tour_requests WHERE agent_account_id=? GROUP BY request_status");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $tour_statuses[$row['request_status']] = (int)$row['cnt']; }
$stmt->close();

// Filter options: Cities from agent's properties
$cities = [];
$stmt = $conn->prepare("SELECT DISTINCT p.City FROM property p JOIN property_log pl ON p.property_ID=pl.property_id AND pl.action='CREATED' WHERE pl.account_id=? ORDER BY p.City ASC");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $cities[] = $row['City']; }
$stmt->close();

// Filter options: Property types
$property_types = [];
$type_result = $conn->query("SELECT type_name AS PropertyType FROM property_types ORDER BY type_name ASC");
if ($type_result) { while ($row = $type_result->fetch_assoc()) { $property_types[] = $row['PropertyType']; } }

// Performance events (agent's closed deals)
$performance_events = [];
$stmt = $conn->prepare("SELECT 'Sold' AS transaction_type, fs.sale_id AS record_id, DATE(fs.finalized_at) AS event_date,
    fs.final_sale_price AS transaction_value, p.property_ID, p.PropertyType, p.City, p.StreetAddress
FROM finalized_sales fs JOIN property p ON fs.property_id=p.property_ID WHERE fs.agent_id=?
UNION ALL
SELECT 'Rented', fr.rental_id, DATE(fr.finalized_at), fr.monthly_rent, p.property_ID, p.PropertyType, p.City, p.StreetAddress
FROM finalized_rentals fr JOIN property p ON fr.property_id=p.property_ID WHERE fr.agent_id=?
ORDER BY event_date DESC");
$stmt->bind_param("ii", $agent_account_id, $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $performance_events[] = $row; }
$stmt->close();

// =============================================
// ENRICHED PROPERTY DATA (Days on Market + Tour Counts)
// =============================================
$tour_counts_by_property = [];
$stmt = $conn->prepare("SELECT tr.property_id, COUNT(*) as tour_count,
    COUNT(CASE WHEN tr.request_status='Completed' THEN 1 END) as completed_tours
FROM tour_requests tr
JOIN property p ON tr.property_id = p.property_ID
JOIN property_log pl ON p.property_ID = pl.property_id AND pl.action='CREATED'
WHERE pl.account_id = ?
GROUP BY tr.property_id");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $tour_counts_by_property[$row['property_id']] = $row; }
$stmt->close();

$total_dom = 0; $active_dom_count = 0;
foreach ($property_report as &$prop) {
    $prop['days_on_market'] = !empty($prop['ListingDate']) ? max(0, (int)((time() - strtotime($prop['ListingDate'])) / 86400)) : 0;
    $pid = $prop['property_ID'];
    $prop['tour_count'] = isset($tour_counts_by_property[$pid]) ? (int)$tour_counts_by_property[$pid]['tour_count'] : 0;
    $prop['completed_tour_count'] = isset($tour_counts_by_property[$pid]) ? (int)$tour_counts_by_property[$pid]['completed_tours'] : 0;
    if (($prop['approval_status'] ?? '') === 'approved' && !in_array($prop['Status'] ?? '', ['Sold','Rented','Pending Sold','Pending Rented'])) {
        $total_dom += $prop['days_on_market']; $active_dom_count++;
    }
}
unset($prop);
$avg_dom = $active_dom_count > 0 ? round($total_dom / $active_dom_count) : 0;

// =============================================
// LEASE EXPIRY TRACKING
// =============================================
$expiring_leases = [];
$stmt = $conn->prepare("SELECT fr.rental_id, fr.tenant_name, fr.tenant_email, fr.tenant_phone,
    fr.monthly_rent, fr.lease_start_date, fr.lease_end_date, fr.lease_status, fr.lease_term_months,
    p.property_ID, p.StreetAddress, p.City, p.PropertyType,
    DATEDIFF(fr.lease_end_date, CURDATE()) AS days_remaining,
    (SELECT COUNT(*) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id AND rp.status = 'Pending') AS pending_pmts,
    (SELECT COUNT(*) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id AND rp.status = 'Confirmed') AS confirmed_pmts,
    (SELECT COUNT(*) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id) AS total_pmts
FROM finalized_rentals fr
JOIN property p ON fr.property_id = p.property_ID
WHERE fr.agent_id = ? AND fr.lease_status IN ('Active','Renewed')
ORDER BY fr.lease_end_date ASC");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $expiring_leases[] = $row; }
$stmt->close();

// =============================================
// COMMISSION PIPELINE (by status)
// =============================================
$commission_pipeline = ['pending' => 0, 'calculated' => 0, 'paid' => 0, 'cancelled' => 0];
$stmt = $conn->prepare("SELECT LOWER(status) AS st, COALESCE(SUM(commission_amount),0) AS total FROM agent_commissions WHERE agent_id = ? GROUP BY st");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { if (isset($commission_pipeline[$row['st']])) $commission_pipeline[$row['st']] = (float)$row['total']; }
$stmt->close();

// =============================================
// MONTHLY COMBINED REVENUE (last 12 months)
// =============================================
$sales_monthly_map = [];
$stmt = $conn->prepare("SELECT DATE_FORMAT(sale_date,'%Y-%m') AS mk, SUM(final_sale_price) AS rev, COUNT(*) AS cnt
    FROM finalized_sales WHERE agent_id=? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY mk");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $sales_monthly_map[$row['mk']] = $row; }
$stmt->close();

$rental_monthly_map = [];
$stmt = $conn->prepare("SELECT DATE_FORMAT(rp.payment_date,'%Y-%m') AS mk, SUM(rp.payment_amount) AS rev, COUNT(*) AS cnt
    FROM rental_payments rp JOIN finalized_rentals fr ON rp.rental_id=fr.rental_id
    WHERE fr.agent_id=? AND rp.status='Confirmed' AND rp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY mk");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $rental_monthly_map[$row['mk']] = $row; }
$stmt->close();

$monthly_revenue = [];
for ($i = 11; $i >= 0; $i--) {
    $dt = new DateTime(); $dt->modify("-$i months");
    $mk = $dt->format('Y-m'); $ml = $dt->format('M Y');
    $monthly_revenue[] = [
        'month_key' => $mk, 'month_label' => $ml,
        'sales_revenue' => (float)($sales_monthly_map[$mk]['rev'] ?? 0),
        'rental_revenue' => (float)($rental_monthly_map[$mk]['rev'] ?? 0),
        'sales_count' => (int)($sales_monthly_map[$mk]['cnt'] ?? 0),
        'rental_count' => (int)($rental_monthly_map[$mk]['cnt'] ?? 0)
    ];
}

// =============================================
// TOUR REQUEST MONTHLY TREND
// =============================================
$tour_monthly = [];
$stmt = $conn->prepare("SELECT DATE_FORMAT(requested_at,'%Y-%m') AS mk, DATE_FORMAT(requested_at,'%b %Y') AS ml, COUNT(*) AS cnt,
    COUNT(CASE WHEN request_status='Completed' THEN 1 END) AS completed
FROM tour_requests WHERE agent_account_id=? AND requested_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY mk ORDER BY mk");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $tour_monthly[] = $row; }
$stmt->close();

// =============================================
// SALE PRICE VS LISTING PRICE COMPARISON
// =============================================
$price_comparisons = [];
$stmt = $conn->prepare("SELECT p.property_ID, p.StreetAddress, p.City, p.PropertyType, p.ListingPrice, fs.final_sale_price, fs.sale_date,
    ROUND(((fs.final_sale_price - p.ListingPrice) / NULLIF(p.ListingPrice,0)) * 100, 1) AS price_diff_pct
FROM finalized_sales fs JOIN property p ON fs.property_id = p.property_ID WHERE fs.agent_id = ? ORDER BY fs.sale_date DESC");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $price_comparisons[] = $row; }
$stmt->close();

// =============================================
// ADDITIONAL KPI CALCULATIONS
// =============================================
$tour_completion_rate = ($tour_stats['total_tours'] > 0) ? round(($tour_stats['completed_tours'] / $tour_stats['total_tours']) * 100) : 0;

$total_rental_payments_count = 0; $confirmed_rental_payments_count = 0;
foreach ($rental_report as $rr) {
    $total_rental_payments_count += (int)($rr['confirmed_payments'] ?? 0) + (int)($rr['pending_payments'] ?? 0);
    $confirmed_rental_payments_count += (int)($rr['confirmed_payments'] ?? 0);
}
$collection_rate = ($total_rental_payments_count > 0) ? round(($confirmed_rental_payments_count / $total_rental_payments_count) * 100) : 0;

$pending_commission_total = $commission_pipeline['pending'] + $commission_pipeline['calculated'];

// Properties by city chart data
$prop_by_city = [];
$stmt = $conn->prepare("SELECT p.City, COUNT(*) as cnt FROM property p JOIN property_log pl ON p.property_ID=pl.property_id AND pl.action='CREATED' WHERE pl.account_id=? GROUP BY p.City ORDER BY cnt DESC LIMIT 10");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $prop_by_city[$row['City']] = (int)$row['cnt']; }
$stmt->close();

// Lease status distribution chart data
$lease_status_dist = [];
$stmt = $conn->prepare("SELECT lease_status, COUNT(*) as cnt FROM finalized_rentals WHERE agent_id=? GROUP BY lease_status");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $lease_status_dist[$row['lease_status']] = (int)$row['cnt']; }
$stmt->close();

// Most toured properties (top 8)
$most_toured = [];
$stmt = $conn->prepare("SELECT p.property_ID, p.StreetAddress, p.City, p.PropertyType, p.ViewsCount, p.Likes,
    COUNT(tr.tour_id) AS tour_count,
    COUNT(CASE WHEN tr.request_status='Completed' THEN 1 END) AS completed_tours
FROM property p
JOIN property_log pl ON p.property_ID = pl.property_id AND pl.action='CREATED'
LEFT JOIN tour_requests tr ON p.property_ID = tr.property_id
WHERE pl.account_id = ?
GROUP BY p.property_ID
HAVING tour_count > 0
ORDER BY tour_count DESC LIMIT 8");
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $most_toured[] = $row; }
$stmt->close();

// Price range distribution
$price_ranges = ['Under ₱1M' => 0, '₱1M-₱5M' => 0, '₱5M-₱10M' => 0, '₱10M-₱20M' => 0, 'Over ₱20M' => 0];
foreach ($property_report as $pr) {
    $lp = (float)($pr['ListingPrice'] ?? 0);
    if ($lp < 1000000) $price_ranges['Under ₱1M']++;
    elseif ($lp < 5000000) $price_ranges['₱1M-₱5M']++;
    elseif ($lp < 10000000) $price_ranges['₱5M-₱10M']++;
    elseif ($lp < 20000000) $price_ranges['₱10M-₱20M']++;
    else $price_ranges['Over ₱20M']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/Logo.png" type="image/png">
    <title>Reports &amp; Analytics - HomeEstate Realty</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    <script src="<?= ASSETS_JS ?>chart.umd.min.js"></script>
    <script src="<?= ASSETS_JS ?>jspdf.umd.min.js"></script>
    <script src="<?= ASSETS_JS ?>jspdf.plugin.autotable.min.js"></script>
    <script src="<?= ASSETS_JS ?>xlsx.full.min.js"></script>
    <style>
        :root {
            --gold:#d4af37;--gold-light:#f4d03f;--gold-dark:#b8941f;
            --blue:#2563eb;--blue-light:#3b82f6;--blue-dark:#1e40af;
            --black:#0a0a0a;--black-light:#111111;--black-lighter:#1a1a1a;
            --white:#ffffff;
            --gray-50:#f8f9fa;--gray-100:#e8e9eb;--gray-200:#d1d4d7;--gray-300:#b8bec4;
            --gray-400:#9ca4ab;--gray-500:#7a8a99;--gray-600:#5d6d7d;--gray-700:#3f4b56;
            --gray-800:#2a3138;--gray-900:#1a1f24;
            --card-bg:linear-gradient(135deg,rgba(26,26,26,0.8) 0%,rgba(10,10,10,0.9) 100%);
            --card-border:rgba(37,99,235,0.15);
            --card-hover-border:rgba(37,99,235,0.35);
            --green:#22c55e;--red:#ef4444;--orange:#f59e0b;--purple:#a855f7;--cyan:#06b6d4;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--black);color:var(--white);line-height:1.6;overflow-x:hidden;}
        ::-webkit-scrollbar{width:8px;}
        ::-webkit-scrollbar-track{background:rgba(26,26,26,0.4);}
        ::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--gold),var(--gold-dark));border-radius:4px;}
        ::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,var(--gold-light),var(--gold));}

        .dashboard-content{padding:2rem;max-width:1440px;margin:0 auto;}

        /* PAGE HEADER */
        .page-header{background:var(--card-bg);border:1px solid var(--card-border);border-radius:4px;padding:2rem 2.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden;}
        .page-header::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:radial-gradient(ellipse at top right,rgba(37,99,235,0.06) 0%,transparent 50%),radial-gradient(ellipse at bottom left,rgba(212,175,55,0.04) 0%,transparent 50%);pointer-events:none;}
        .page-header::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),var(--blue),transparent);}
        .page-header-inner{position:relative;z-index:2;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;}
        .page-header h1{font-size:1.75rem;font-weight:800;background:linear-gradient(135deg,var(--white) 0%,var(--gray-100) 50%,var(--gold) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:0.25rem;}
        .page-header .subtitle{color:var(--gray-400);font-size:0.95rem;}
        .action-buttons{display:flex;gap:0.75rem;flex-wrap:wrap;}
        .btn-export{display:inline-flex;align-items:center;gap:0.5rem;padding:0.6rem 1.25rem;font-size:0.85rem;font-weight:600;border-radius:4px;border:none;cursor:pointer;transition:all .3s ease;}
        .btn-export-pdf{background:linear-gradient(135deg,rgba(239,68,68,0.15),rgba(239,68,68,0.25));color:#ef4444;border:1px solid rgba(239,68,68,0.3);}
        .btn-export-pdf:hover{background:linear-gradient(135deg,rgba(239,68,68,0.25),rgba(239,68,68,0.35));transform:translateY(-2px);box-shadow:0 4px 16px rgba(239,68,68,0.2);}
        .btn-export-excel{background:linear-gradient(135deg,rgba(34,197,94,0.15),rgba(34,197,94,0.25));color:#22c55e;border:1px solid rgba(34,197,94,0.3);}
        .btn-export-excel:hover{background:linear-gradient(135deg,rgba(34,197,94,0.25),rgba(34,197,94,0.35));transform:translateY(-2px);box-shadow:0 4px 16px rgba(34,197,94,0.2);}
        .btn-filter{background:linear-gradient(135deg,rgba(37,99,235,0.12),rgba(37,99,235,0.2));color:var(--blue-light);border:1px solid rgba(37,99,235,0.25);display:inline-flex;align-items:center;gap:0.5rem;padding:0.6rem 1.25rem;font-size:0.85rem;font-weight:600;border-radius:4px;cursor:pointer;transition:all .3s ease;}
        .btn-filter:hover{background:linear-gradient(135deg,rgba(37,99,235,0.2),rgba(37,99,235,0.3));transform:translateY(-2px);}
        .filter-count-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;background:var(--blue);color:#fff;border-radius:10px;font-size:0.7rem;font-weight:700;}

        /* KPI CARDS */
        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;}
        .kpi-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:4px;padding:1.25rem;position:relative;overflow:hidden;transition:all .3s ease;}
        .kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--blue),transparent);opacity:0;transition:opacity .3s ease;}
        .kpi-card:hover{border-color:var(--card-hover-border);box-shadow:0 8px 32px rgba(37,99,235,0.12);transform:translateY(-3px);}
        .kpi-card:hover::before{opacity:1;}
        .kpi-card .kpi-icon{width:40px;height:40px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:0.75rem;}
        .kpi-icon.gold{background:linear-gradient(135deg,rgba(212,175,55,0.1),rgba(212,175,55,0.2));color:var(--gold);border:1px solid rgba(212,175,55,0.2);}
        .kpi-icon.blue{background:linear-gradient(135deg,rgba(37,99,235,0.1),rgba(37,99,235,0.2));color:var(--blue-light);border:1px solid rgba(37,99,235,0.2);}
        .kpi-icon.green{background:linear-gradient(135deg,rgba(34,197,94,0.1),rgba(34,197,94,0.2));color:#22c55e;border:1px solid rgba(34,197,94,0.2);}
        .kpi-icon.red{background:linear-gradient(135deg,rgba(239,68,68,0.1),rgba(239,68,68,0.2));color:#ef4444;border:1px solid rgba(239,68,68,0.2);}
        .kpi-icon.purple{background:linear-gradient(135deg,rgba(168,85,247,0.1),rgba(168,85,247,0.2));color:#a855f7;border:1px solid rgba(168,85,247,0.2);}
        .kpi-icon.cyan{background:linear-gradient(135deg,rgba(6,182,212,0.1),rgba(6,182,212,0.2));color:#06b6d4;border:1px solid rgba(6,182,212,0.2);}
        .kpi-icon.orange{background:linear-gradient(135deg,rgba(245,158,11,0.1),rgba(245,158,11,0.2));color:#f59e0b;border:1px solid rgba(245,158,11,0.2);}
        .kpi-card .kpi-label{font-size:0.7rem;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:var(--gray-400);margin-bottom:0.25rem;}
        .kpi-card .kpi-value{font-size:1.5rem;font-weight:800;color:var(--white);line-height:1.2;}
        .kpi-card .kpi-sub{font-size:0.72rem;color:var(--gray-500);margin-top:0.25rem;font-weight:500;}

        /* REPORT TABS */
        .report-tabs{background:var(--card-bg);border:1px solid var(--card-border);border-radius:4px;overflow:hidden;margin-bottom:1.5rem;position:relative;}
        .report-tabs::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),var(--blue),transparent);z-index:5;}
        .report-tabs .nav-tabs{border-bottom:1px solid rgba(37,99,235,0.15);padding:0.25rem 0.5rem 0;gap:0.25rem;background:linear-gradient(180deg,rgba(15,15,15,0.6),rgba(10,10,10,0.8));}
        .report-tabs .nav-link{border:none;border-bottom:3px solid transparent;background:transparent;color:var(--gray-400);font-weight:600;font-size:0.85rem;padding:0.85rem 1.25rem;border-radius:0;}
        .report-tabs .nav-link:hover{color:var(--white);background:rgba(37,99,235,0.06);border-bottom-color:rgba(37,99,235,0.3);}
        .report-tabs .nav-link.active{color:var(--gold);background:rgba(212,175,55,0.05);border-bottom-color:var(--gold);}
        .tab-badge{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 0.4rem;border-radius:2px;font-size:0.7rem;font-weight:700;margin-left:0.5rem;}
        .badge-gold{background:rgba(212,175,55,0.15);color:var(--gold);border:1px solid rgba(212,175,55,0.2);}
        .badge-blue{background:rgba(37,99,235,0.15);color:var(--blue-light);border:1px solid rgba(37,99,235,0.2);}
        .badge-green{background:rgba(34,197,94,0.15);color:#22c55e;border:1px solid rgba(34,197,94,0.2);}
        .badge-cyan{background:rgba(6,182,212,0.15);color:#06b6d4;border:1px solid rgba(6,182,212,0.2);}
        .badge-purple{background:rgba(168,85,247,0.15);color:#a855f7;border:1px solid rgba(168,85,247,0.2);}
        .tab-content{padding:1.5rem;}

        /* CHART CARDS */
        .chart-grid{display:grid;gap:1.5rem;margin-bottom:1.5rem;}
        .chart-grid-2{grid-template-columns:1fr 1fr;}
        .chart-grid-3{grid-template-columns:1fr 1fr 1fr;}
        .chart-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:4px;padding:1.5rem;position:relative;overflow:hidden;transition:all .3s ease;}
        .chart-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--blue),transparent);opacity:0;transition:opacity .3s ease;}
        .chart-card:hover{border-color:rgba(37,99,235,0.25);box-shadow:0 8px 32px rgba(37,99,235,0.08);}
        .chart-card:hover::before{opacity:1;}
        .chart-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid rgba(37,99,235,0.1);}
        .chart-card-title{font-size:0.85rem;font-weight:700;color:var(--white);display:flex;align-items:center;gap:0.5rem;}
        .chart-card-title i{color:var(--gold);font-size:1rem;}
        .chart-container{position:relative;width:100%;}
        .chart-container.h-250{height:250px;}
        .chart-container.h-280{height:280px;}

        /* DATA TABLES */
        .report-table-wrapper{overflow-x:auto;border-radius:4px;border:1px solid var(--card-border);background:rgba(10,10,10,0.6);}
        .report-table{width:100%;border-collapse:separate;border-spacing:0;font-size:0.82rem;}
        .report-table thead th{position:sticky;top:0;z-index:3;background:linear-gradient(180deg,#0f172a 0%,#1e293b 100%);color:rgba(212,175,55,0.8);font-weight:700;font-size:0.68rem;text-transform:uppercase;letter-spacing:1px;padding:0.9rem 1rem;border-bottom:2px solid var(--gold-dark);white-space:nowrap;border-right:1px solid rgba(255,255,255,0.06);}
        .report-table thead th:first-child{width:44px;text-align:center;color:rgba(212,175,55,0.5);}
        .report-table thead th:last-child{border-right:none;}
        .report-table tbody td{padding:0.78rem 1rem;border-bottom:1px solid rgba(37,99,235,0.06);color:var(--gray-300);vertical-align:middle;border-right:1px solid rgba(37,99,235,0.04);}
        .report-table tbody td:first-child{text-align:center;font-weight:700;font-size:0.72rem;color:var(--gray-500);background:rgba(15,23,42,0.3);border-right:2px solid rgba(212,175,55,0.1);}
        .report-table tbody td:last-child{border-right:none;}
        .report-table tbody tr:nth-child(even) td{background:rgba(26,26,26,0.4);}
        .report-table tbody tr:hover td{background:rgba(212,175,55,0.04) !important;}
        .report-table tbody tr:hover td:first-child{color:var(--gold);}
        .report-table tbody tr:last-child td{border-bottom:none;}
        .price-text{font-weight:700;color:var(--gold);}

        .status-pill{display:inline-flex;align-items:center;gap:0.3rem;padding:0.22rem 0.65rem;border-radius:20px;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;}
        .status-pill.for-sale{background:rgba(37,99,235,0.15);color:#3b82f6;}
        .status-pill.for-rent{background:rgba(212,175,55,0.15);color:var(--gold);}
        .status-pill.sold{background:rgba(100,116,139,0.15);color:#94a3b8;}
        .status-pill.rented{background:rgba(6,182,212,0.15);color:#06b6d4;}
        .status-pill.pending{background:rgba(245,158,11,0.15);color:#f59e0b;}
        .status-pill.pending-sold{background:rgba(245,158,11,0.15);color:#f59e0b;}
        .status-pill.pending-rented{background:rgba(245,158,11,0.15);color:#f59e0b;}
        .status-pill.approved{background:rgba(34,197,94,0.15);color:#22c55e;}
        .status-pill.rejected{background:rgba(239,68,68,0.15);color:#ef4444;}
        .status-pill.confirmed{background:rgba(37,99,235,0.15);color:#3b82f6;}
        .status-pill.completed{background:rgba(34,197,94,0.15);color:#22c55e;}
        .status-pill.cancelled{background:rgba(239,68,68,0.15);color:#ef4444;}
        .status-pill.expired{background:rgba(100,116,139,0.15);color:#94a3b8;}
        .status-pill.active{background:rgba(34,197,94,0.15);color:#22c55e;}
        .status-pill.renewed{background:rgba(37,99,235,0.15);color:#3b82f6;}
        .status-pill.terminated{background:rgba(239,68,68,0.15);color:#ef4444;}
        .status-pill.paid{background:rgba(34,197,94,0.15);color:#22c55e;}
        .status-pill.calculated{background:rgba(37,99,235,0.15);color:#3b82f6;}
        .status-pill.processing{background:rgba(245,158,11,0.15);color:#f59e0b;}
        .status-pill.sale{background:rgba(37,99,235,0.15);color:#3b82f6;}
        .status-pill.rental{background:rgba(212,175,55,0.15);color:var(--gold);}

        /* PAGINATION */
        .report-pagination{display:flex;align-items:center;justify-content:space-between;padding:1rem 0 0;border-top:1px solid rgba(37,99,235,0.1);margin-top:1rem;flex-wrap:wrap;gap:0.75rem;}
        .pagination-info{font-size:0.8rem;color:var(--gray-500);font-weight:500;}
        .pagination-controls{display:flex;gap:0.25rem;}
        .page-btn{width:36px;height:36px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(37,99,235,0.15);background:transparent;color:var(--gray-400);border-radius:4px;font-size:0.8rem;font-weight:600;cursor:pointer;transition:all .2s ease;}
        .page-btn:hover:not(:disabled):not(.active){border-color:var(--blue);color:var(--blue-light);background:rgba(37,99,235,0.08);}
        .page-btn.active{background:linear-gradient(135deg,var(--gold-dark),var(--gold));color:#000;border-color:var(--gold);}
        .page-btn:disabled{opacity:0.4;cursor:not-allowed;}

        /* EMPTY STATE */
        .empty-state{text-align:center;padding:3rem 1rem;color:var(--gray-500);}
        .empty-state i{font-size:3rem;color:rgba(37,99,235,0.15);margin-bottom:1rem;display:block;}
        .empty-state h4{font-weight:700;color:var(--white);margin-bottom:0.5rem;}

        /* FILTER SIDEBAR */
        .filter-sidebar{position:fixed;top:0;right:0;width:100%;height:100%;z-index:9999;pointer-events:none;}
        .filter-sidebar.active{pointer-events:all;}
        .filter-sidebar-overlay{position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);opacity:0;transition:opacity .2s ease;pointer-events:none;}
        .filter-sidebar.active .filter-sidebar-overlay{opacity:1;pointer-events:all;}
        .filter-sidebar-content{position:absolute;top:0;right:0;width:480px;max-width:90vw;height:100%;background:linear-gradient(180deg,#0f0f0f,#0a0a0a);border-left:1px solid var(--card-border);box-shadow:-8px 0 32px rgba(0,0,0,0.5);transform:translateX(100%);transition:transform .25s ease;display:flex;flex-direction:column;overflow:hidden;}
        .filter-sidebar.active .filter-sidebar-content{transform:translateX(0);}
        .filter-header{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:1.5rem 2rem;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
        .filter-header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--blue));}
        .filter-header h4{font-weight:700;font-size:1.15rem;display:flex;align-items:center;gap:0.75rem;margin:0;}
        .filter-header h4 i{color:var(--gold);font-size:1.3rem;}
        .btn-close-filter{background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;width:36px;height:36px;border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s ease;font-size:1rem;}
        .btn-close-filter:hover{background:rgba(239,68,68,0.2);border-color:rgba(239,68,68,0.4);}
        .filter-body{flex:1;overflow-y:auto;padding:1.5rem;background:rgba(10,10,10,0.5);}
        .filter-section{background:rgba(26,26,26,0.6);border-radius:4px;padding:1.25rem;margin-bottom:1rem;border:1px solid rgba(37,99,235,0.1);}
        .filter-section-title{font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--gray-300);margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;padding-bottom:0.75rem;border-bottom:1px solid rgba(37,99,235,0.1);}
        .filter-section-title i{color:var(--gold);font-size:0.95rem;}
        .filter-select{width:100%;padding:0.55rem 0.85rem;border:1px solid rgba(37,99,235,0.15);border-radius:4px;font-size:0.85rem;font-weight:500;color:var(--white);background:rgba(15,15,15,0.8);transition:all .2s ease;cursor:pointer;}
        .filter-select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,0.15);}
        .filter-select option{background:#1a1a1a;color:var(--white);}
        .filter-input{width:100%;padding:0.55rem 0.85rem;border:1px solid rgba(37,99,235,0.15);border-radius:4px;font-size:0.85rem;color:var(--white);background:rgba(15,15,15,0.8);font-family:inherit;color-scheme:dark;}
        .filter-input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,0.15);}
        .filter-chips{display:flex;flex-wrap:wrap;gap:0.5rem;}
        .filter-chip{display:inline-flex;align-items:center;gap:0.35rem;padding:0.4rem 0.75rem;border:1px solid rgba(37,99,235,0.15);border-radius:4px;font-size:0.8rem;font-weight:500;color:var(--gray-400);cursor:pointer;transition:all .2s ease;background:transparent;}
        .filter-chip:hover{border-color:var(--gold);color:var(--gold);}
        .filter-chip:has(input:checked){background:rgba(212,175,55,0.1);border-color:var(--gold);color:var(--gold);}
        .filter-chip input[type="checkbox"]{width:14px;height:14px;accent-color:var(--gold);cursor:pointer;}
        .filter-results-summary{background:rgba(37,99,235,0.06);border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:1rem 1.25rem;display:flex;align-items:center;gap:0.75rem;margin-top:1rem;}
        .filter-results-summary i{color:var(--gold);font-size:1.2rem;}
        .filter-results-count{font-size:1.2rem;font-weight:800;color:var(--white);}
        .filter-results-label{font-size:0.75rem;color:var(--gray-500);}
        .filter-footer{padding:1.25rem 1.5rem;border-top:1px solid rgba(37,99,235,0.1);display:flex;gap:0.75rem;background:rgba(15,15,15,0.9);}
        .filter-footer .btn{flex:1;padding:0.65rem 1rem;font-size:0.85rem;font-weight:700;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;cursor:pointer;border:none;}
        .filter-footer .btn-outline-secondary{background:transparent;border:1px solid rgba(255,255,255,0.1);color:var(--gray-400);}
        .filter-footer .btn-outline-secondary:hover{border-color:rgba(255,255,255,0.2);color:var(--white);}
        .filter-footer .btn-primary{background:linear-gradient(135deg,var(--gold-dark),var(--gold));color:#000;}
        .filter-footer .btn-primary:hover{box-shadow:0 4px 12px rgba(212,175,55,0.25);}

        /* EXPORT PREVIEW MODAL */
        .export-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);z-index:10000;display:none;align-items:center;justify-content:center;padding:1.5rem;}
        .export-modal-overlay.active{display:flex;}
        .export-modal{background:linear-gradient(180deg,#111,#0a0a0a);border:1px solid var(--card-border);border-radius:8px;width:100%;max-width:1200px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,0.5);overflow:hidden;}
        .export-modal-header{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:1.25rem 2rem;display:flex;align-items:center;justify-content:space-between;position:relative;}
        .export-modal-header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--blue),var(--gold));}
        .export-modal-body{flex:1;overflow:auto;padding:1.5rem 2rem;background:rgba(10,10,10,0.5);}
        .export-preview-table{width:100%;border-collapse:collapse;font-size:0.78rem;background:rgba(15,15,15,0.8);border:1px solid rgba(37,99,235,0.1);border-radius:4px;overflow:hidden;}
        .export-preview-table thead th{background:linear-gradient(180deg,#0f172a,#1e293b);color:rgba(212,175,55,0.8);font-weight:700;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.5px;padding:0.75rem 0.85rem;border-bottom:2px solid rgba(212,175,55,0.3);white-space:nowrap;}
        .export-preview-table tbody td{padding:0.6rem 0.85rem;border-bottom:1px solid rgba(37,99,235,0.06);color:var(--gray-300);}
        .export-preview-table tbody tr:nth-child(even){background:rgba(26,26,26,0.3);}
        .export-preview-table tbody tr:hover{background:rgba(212,175,55,0.04);}
        .export-modal-footer{padding:1.25rem 2rem;border-top:1px solid rgba(37,99,235,0.1);display:flex;align-items:center;justify-content:space-between;background:rgba(15,15,15,0.9);flex-wrap:wrap;gap:0.75rem;}
        .export-modal-footer .export-info{font-size:0.82rem;color:var(--gray-500);font-weight:500;}
        .export-modal-footer .export-info strong{color:var(--white);}

        /* PERFORMANCE TREND */
        .trend-filter-bar{background:var(--card-bg);border:1px solid var(--card-border);border-radius:4px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:flex-end;flex-wrap:wrap;gap:1rem;}
        .trend-filter-group{display:inline-flex;flex-direction:column;gap:0.35rem;}
        .trend-filter-group label{font-size:0.7rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;color:var(--gray-400);margin:0;}
        .trend-filter-group .filter-select{height:38px;padding:0 0.75rem;border:1px solid rgba(37,99,235,0.15);border-radius:4px;font-size:0.85rem;color:var(--white);min-width:160px;background:rgba(15,15,15,0.8);}
        .trend-filter-group .filter-input{height:38px;padding:0 0.75rem;border:1px solid rgba(37,99,235,0.15);border-radius:4px;font-size:0.85rem;color:var(--white);background:rgba(15,15,15,0.8);}
        .trend-filter-actions{display:inline-flex;align-items:center;}
        .btn-gold-sm{background:linear-gradient(135deg,var(--gold-dark),var(--gold));color:#000;border:none;padding:0.5rem 1rem;font-size:0.85rem;font-weight:700;border-radius:4px;cursor:pointer;height:38px;display:inline-flex;align-items:center;gap:0.4rem;transition:all .2s;}
        .btn-gold-sm:hover{box-shadow:0 4px 12px rgba(212,175,55,0.25);transform:translateY(-1px);}

        /* RESPONSIVE */
        @media(max-width:1200px){.chart-grid-2,.chart-grid-3{grid-template-columns:1fr;}}
        @media(max-width:768px){
            .dashboard-content{padding:1rem;}
            .page-header{padding:1.5rem;}
            .page-header-inner{flex-direction:column;text-align:center;}
            .action-buttons{justify-content:center;}
            .report-tabs .nav-tabs{overflow-x:auto;flex-wrap:nowrap;}
            .report-tabs .nav-link{padding:0.65rem 0.85rem;font-size:0.8rem;white-space:nowrap;}
            .tab-content{padding:1rem;}
            .filter-sidebar-content{width:100%;max-width:100%;}
            .trend-filter-bar{flex-direction:column;align-items:stretch;}
        }
        @media(max-width:576px){
            .kpi-card .kpi-value{font-size:1.1rem;}
            .tab-badge{display:none;}
        }

        /* SKELETON SCREEN */
        @keyframes sk-shimmer{0%{background-position:-800px 0;}100%{background-position:800px 0;}}
        .sk-shimmer{background:linear-gradient(90deg,rgba(255,255,255,0.03) 25%,rgba(255,255,255,0.06) 50%,rgba(255,255,255,0.03) 75%);background-size:1600px 100%;animation:sk-shimmer 1.6s ease-in-out infinite;border-radius:4px;}
        #page-content{display:none;}
        .sk-page-header{background:linear-gradient(135deg,#0a0a0a,#1a1a1a);border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:2rem 2.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden;}
        .sk-page-header::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#d4af37,#2563eb,transparent);}
        .sk-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;}
        .sk-kpi-card{background:linear-gradient(135deg,rgba(26,26,26,0.8),rgba(10,10,10,0.9));border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:1.25rem;display:flex;flex-direction:column;gap:0.6rem;}
        .sk-kpi-icon{width:40px;height:40px;border-radius:4px;flex-shrink:0;}
        .sk-tabs{background:linear-gradient(135deg,rgba(26,26,26,0.8),rgba(10,10,10,0.9));border:1px solid rgba(37,99,235,0.15);border-radius:4px;padding:0.75rem 1rem;margin-bottom:1.5rem;display:flex;gap:0.75rem;min-height:56px;position:relative;overflow:hidden;}
        .sk-tabs::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#d4af37,#2563eb,transparent);}
        .sk-table-wrap{background:linear-gradient(135deg,rgba(26,26,26,0.8),rgba(10,10,10,0.9));border:1px solid rgba(37,99,235,0.15);border-radius:4px;overflow:hidden;}
        .sk-table-head{background:linear-gradient(180deg,#0f172a,#1e293b);padding:0.9rem 1rem;display:flex;gap:0.75rem;}
        .sk-table-row{display:flex;gap:0.75rem;padding:0.78rem 1rem;border-bottom:1px solid rgba(37,99,235,0.06);}
        .sk-line{display:block;border-radius:4px;}

        /* TOAST */
        #toastContainer{position:fixed;top:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:0.6rem;pointer-events:none;}
        .app-toast{display:flex;align-items:flex-start;gap:0.85rem;background:linear-gradient(135deg,rgba(26,26,26,0.97),rgba(10,10,10,0.98));border:1px solid rgba(37,99,235,0.15);border-radius:12px;padding:0.9rem 1.1rem;min-width:300px;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,0.5),0 0 0 1px rgba(255,255,255,0.04);pointer-events:all;position:relative;overflow:hidden;animation:toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;backdrop-filter:blur(12px);}
        @keyframes toast-in{from{opacity:0;transform:translateX(60px) scale(.95);}to{opacity:1;transform:translateX(0) scale(1);}}
        .app-toast.toast-out{animation:toast-out .3s ease forwards;}
        @keyframes toast-out{to{opacity:0;transform:translateX(60px) scale(.9);max-height:0;padding:0;margin:0;}}
        .app-toast::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;}
        .app-toast.toast-success::before{background:linear-gradient(180deg,#d4af37,#b8941f);}
        .app-toast.toast-error::before{background:linear-gradient(180deg,#ef4444,#dc2626);}
        .app-toast.toast-info::before{background:linear-gradient(180deg,#2563eb,#1e40af);}
        .app-toast-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
        .toast-success .app-toast-icon{background:rgba(212,175,55,0.15);color:#d4af37;}
        .toast-error .app-toast-icon{background:rgba(239,68,68,0.12);color:#ef4444;}
        .toast-info .app-toast-icon{background:rgba(37,99,235,0.12);color:#3b82f6;}
        .app-toast-body{flex:1;min-width:0;}
        .app-toast-title{font-size:0.82rem;font-weight:700;color:#f1f5f9;margin-bottom:0.2rem;}
        .app-toast-msg{font-size:0.78rem;color:#9ca4ab;line-height:1.4;word-break:break-word;}
        .app-toast-close{background:none;border:none;cursor:pointer;color:#5d6d7d;font-size:0.8rem;padding:0;line-height:1;flex-shrink:0;transition:color .2s;}
        .app-toast-close:hover{color:#f1f5f9;}
        .app-toast-progress{position:absolute;bottom:0;left:0;height:2px;border-radius:0 0 0 12px;}
        .toast-success .app-toast-progress{background:linear-gradient(90deg,#d4af37,#b8941f);}
        .toast-error .app-toast-progress{background:linear-gradient(90deg,#ef4444,#dc2626);}
        .toast-info .app-toast-progress{background:linear-gradient(90deg,#2563eb,#1e40af);}
        @keyframes toast-progress{from{width:100%;}to{width:0%;}}
    </style>
</head>
<body>

<?php $active_page = 'agent_reports.php'; include 'agent_navbar.php'; ?>

<div class="dashboard-content">

    <noscript><style>#sk-screen{display:none!important;}#page-content{display:block!important;opacity:1!important;}</style></noscript>

    <!-- SKELETON SCREEN -->
    <div id="sk-screen" role="presentation" aria-hidden="true">
        <div class="sk-page-header"><div class="sk-line sk-shimmer" style="width:280px;height:24px;margin-bottom:10px;"></div><div class="sk-line sk-shimmer" style="width:380px;height:13px;"></div></div>
        <div class="sk-kpi-grid">
            <?php for($i=0;$i<10;$i++):?><div class="sk-kpi-card"><div class="sk-kpi-icon sk-shimmer"></div><div class="sk-line sk-shimmer" style="width:70px;height:11px;"></div><div class="sk-line sk-shimmer" style="width:50px;height:26px;"></div><div class="sk-line sk-shimmer" style="width:90px;height:11px;"></div></div><?php endfor;?>
        </div>
        <div class="sk-tabs"><div class="sk-shimmer" style="width:90px;height:20px;"></div><div class="sk-shimmer" style="width:70px;height:20px;"></div><div class="sk-shimmer" style="width:80px;height:20px;"></div><div class="sk-shimmer" style="width:70px;height:20px;"></div><div class="sk-shimmer" style="width:100px;height:20px;"></div><div class="sk-shimmer" style="width:75px;height:20px;"></div></div>
        <div class="sk-table-wrap"><div class="sk-table-head"><?php for($i=0;$i<7;$i++):?><div class="sk-shimmer" style="width:<?=rand(60,120)?>px;height:12px;"></div><?php endfor;?></div>
        <?php for($i=0;$i<8;$i++):?><div class="sk-table-row"><?php for($j=0;$j<7;$j++):?><div class="sk-shimmer" style="width:<?=rand(50,130)?>px;height:14px;"></div><?php endfor;?></div><?php endfor;?></div>
    </div>

    <!-- REAL CONTENT -->
    <div id="page-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-inner">
            <div>
                <h1><i class="bi bi-graph-up-arrow me-2"></i>Reports &amp; Analytics</h1>
                <div class="subtitle">Performance data for <?= $agent_name ?> &bull; <?= date('F j, Y') ?></div>
            </div>
            <div class="action-buttons">
                <button class="btn-filter" id="openFilterSidebar"><i class="bi bi-funnel"></i> Filters <span class="filter-count-badge" id="filterBadge" style="display:none;">0</span></button>
                <button class="btn-export btn-export-pdf" id="btnExportPDF"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
                <button class="btn-export btn-export-excel" id="btnExportExcel"><i class="bi bi-file-earmark-spreadsheet"></i> Export Excel</button>
            </div>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="kpi-grid">
        <div class="kpi-card"><div class="kpi-icon gold"><i class="bi bi-buildings"></i></div><div class="kpi-label">Total Listings</div><div class="kpi-value"><?= number_format($prop_stats['total_listings'] ?? 0) ?></div><div class="kpi-sub"><?= $prop_stats['active_listings'] ?? 0 ?> active &bull; <?= $prop_stats['pending_approval'] ?? 0 ?> pending</div></div>
        <div class="kpi-card"><div class="kpi-icon green"><i class="bi bi-check-circle"></i></div><div class="kpi-label">Closed Sales</div><div class="kpi-value"><?= number_format($sales_stats['total_sales'] ?? 0) ?></div><div class="kpi-sub">&#8369;<?= number_format($sales_stats['total_revenue'] ?? 0) ?> revenue</div></div>
        <div class="kpi-card"><div class="kpi-icon blue"><i class="bi bi-key"></i></div><div class="kpi-label">Active Leases</div><div class="kpi-value"><?= number_format($rental_stats['active_leases'] ?? 0) ?></div><div class="kpi-sub">&#8369;<?= number_format($rental_stats['monthly_rental_income'] ?? 0) ?>/mo</div></div>
        <div class="kpi-card"><div class="kpi-icon orange"><i class="bi bi-wallet2"></i></div><div class="kpi-label">Total Commission</div><div class="kpi-value">&#8369;<?= number_format(($comm_stats['total_commission'] ?? 0) + ($rental_comm['total'] ?? 0)) ?></div><div class="kpi-sub">Sales + Rental</div></div>
        <div class="kpi-card"><div class="kpi-icon cyan"><i class="bi bi-calendar-check"></i></div><div class="kpi-label">Tour Requests</div><div class="kpi-value"><?= number_format($tour_stats['total_tours'] ?? 0) ?></div><div class="kpi-sub"><?= $tour_stats['completed_tours'] ?? 0 ?> completed</div></div>
        <div class="kpi-card"><div class="kpi-icon purple"><i class="bi bi-eye"></i></div><div class="kpi-label">Total Views</div><div class="kpi-value"><?= number_format($prop_stats['total_views'] ?? 0) ?></div><div class="kpi-sub"><?= number_format($prop_stats['total_likes'] ?? 0) ?> likes</div></div>
        <div class="kpi-card"><div class="kpi-icon gold"><i class="bi bi-clock-history"></i></div><div class="kpi-label">Avg Days on Market</div><div class="kpi-value"><?= $avg_dom ?> <span style="font-size:0.8rem;font-weight:600;">days</span></div><div class="kpi-sub"><?= $active_dom_count ?> active listings</div></div>
        <div class="kpi-card"><div class="kpi-icon green"><i class="bi bi-percent"></i></div><div class="kpi-label">Tour Success Rate</div><div class="kpi-value"><?= $tour_completion_rate ?>%</div><div class="kpi-sub"><?= $tour_stats['completed_tours'] ?? 0 ?> of <?= $tour_stats['total_tours'] ?? 0 ?> completed</div></div>
        <div class="kpi-card"><div class="kpi-icon red"><i class="bi bi-hourglass-split"></i></div><div class="kpi-label">Pending Commission</div><div class="kpi-value">&#8369;<?= number_format($pending_commission_total) ?></div><div class="kpi-sub">Awaiting payment</div></div>
        <div class="kpi-card"><div class="kpi-icon cyan"><i class="bi bi-receipt-cutoff"></i></div><div class="kpi-label">Collection Rate</div><div class="kpi-value"><?= $collection_rate ?>%</div><div class="kpi-sub"><?= $confirmed_rental_payments_count ?> of <?= $total_rental_payments_count ?> payments</div></div>
    </div>

    <!-- REPORT TABS -->
    <div class="report-tabs">
        <ul class="nav nav-tabs" id="reportTabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-properties" data-bs-toggle="tab" data-bs-target="#content-properties" type="button" role="tab">Properties <span class="tab-badge badge-gold" id="badge-properties"><?= count($property_report) ?></span></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-sales" data-bs-toggle="tab" data-bs-target="#content-sales" type="button" role="tab">Sales <span class="tab-badge badge-green" id="badge-sales"><?= count($sales_report) ?></span></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-rentals" data-bs-toggle="tab" data-bs-target="#content-rentals" type="button" role="tab">Rentals <span class="tab-badge badge-blue" id="badge-rentals"><?= count($rental_report) ?></span></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-tours" data-bs-toggle="tab" data-bs-target="#content-tours" type="button" role="tab">Tours <span class="tab-badge badge-cyan" id="badge-tours"><?= count($tour_report) ?></span></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-commission" data-bs-toggle="tab" data-bs-target="#content-commission" type="button" role="tab">Commission <span class="tab-badge badge-purple" id="badge-commission"><?= count($commission_report) ?></span></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-insights" data-bs-toggle="tab" data-bs-target="#content-insights" type="button" role="tab"><i class="bi bi-lightbulb me-1"></i>Insights</button></li>
        </ul>

        <div class="tab-content" id="reportTabContent">

            <!-- ========== PROPERTIES TAB ========== -->
            <div class="tab-pane fade show active" id="content-properties" role="tabpanel">
                <div class="chart-grid chart-grid-3" style="margin-bottom:1.5rem;">
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-pie-chart-fill"></i> Properties by Type</div></div>
                        <div class="chart-container h-250"><canvas id="chartPropType"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-bar-chart-fill"></i> Listing Status Breakdown</div></div>
                        <div class="chart-container h-250"><canvas id="chartListingStatus"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-geo-alt-fill"></i> Properties by City</div></div>
                        <div class="chart-container h-250"><canvas id="chartPropCity"></canvas></div>
                    </div>
                </div>
                <div class="report-table-wrapper" style="max-height:500px;overflow-y:auto;">
                    <table class="report-table" id="tableProperties">
                        <thead><tr><th>#</th><th>Address</th><th>City</th><th>Type</th><th>Beds</th><th>Baths</th><th>Listing Price</th><th>Status</th><th>Approval</th><th>Views</th><th>Likes</th><th>Tours</th><th>DOM</th><th>Listed Date</th></tr></thead>
                        <tbody id="tbodyProperties"></tbody>
                    </table>
                </div>
                <div class="report-pagination" id="paginationProperties"></div>
            </div>

            <!-- ========== SALES TAB ========== -->
            <div class="tab-pane fade" id="content-sales" role="tabpanel">
                <div class="chart-grid chart-grid-2" style="margin-bottom:1.5rem;">
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-graph-up"></i> Sales Revenue by Month</div></div>
                        <div class="chart-container h-250"><canvas id="chartSalesMonthly"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-arrow-left-right"></i> Listing vs Sale Price</div></div>
                        <div class="chart-container h-250"><canvas id="chartPriceComparison"></canvas></div>
                    </div>
                </div>
                <div class="report-table-wrapper" style="max-height:500px;overflow-y:auto;">
                    <table class="report-table" id="tableSales">
                        <thead><tr><th>#</th><th>Property</th><th>City</th><th>Type</th><th>Buyer</th><th>Listing Price</th><th>Sale Price</th><th>Diff %</th><th>Sale Date</th><th>Commission</th><th>Comm %</th><th>Comm Status</th></tr></thead>
                        <tbody id="tbodySales"></tbody>
                    </table>
                </div>
                <div class="report-pagination" id="paginationSales"></div>
            </div>

            <!-- ========== RENTALS TAB ========== -->
            <div class="tab-pane fade" id="content-rentals" role="tabpanel">
                <div class="chart-grid chart-grid-2" style="margin-bottom:1.5rem;">
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-pie-chart-fill"></i> Lease Status Distribution</div></div>
                        <div class="chart-container h-250"><canvas id="chartLeaseStatus"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-cash-stack"></i> Monthly Rental Collections</div></div>
                        <div class="chart-container h-250"><canvas id="chartRentalCollections"></canvas></div>
                    </div>
                </div>
                <div class="report-table-wrapper" style="max-height:500px;overflow-y:auto;">
                    <table class="report-table" id="tableRentals">
                        <thead><tr><th>#</th><th>Property</th><th>City</th><th>Type</th><th>Tenant</th><th>Monthly Rent</th><th>Deposit</th><th>Lease Start</th><th>Lease End</th><th>Term</th><th>Collected</th><th>Commission</th><th>Payments</th><th>Status</th></tr></thead>
                        <tbody id="tbodyRentals"></tbody>
                    </table>
                </div>
                <div class="report-pagination" id="paginationRentals"></div>
            </div>

            <!-- ========== TOURS TAB ========== -->
            <div class="tab-pane fade" id="content-tours" role="tabpanel">
                <div class="chart-grid chart-grid-2" style="margin-bottom:1.5rem;">
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-pie-chart-fill"></i> Tour Status Breakdown</div></div>
                        <div class="chart-container h-250"><canvas id="chartTourStatus"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-graph-up"></i> Tour Requests Over Time</div></div>
                        <div class="chart-container h-250"><canvas id="chartTourMonthly"></canvas></div>
                    </div>
                </div>
                <div class="report-table-wrapper" style="max-height:500px;overflow-y:auto;">
                    <table class="report-table" id="tableTours">
                        <thead><tr><th>#</th><th>Visitor</th><th>Email</th><th>Phone</th><th>Property</th><th>City</th><th>Tour Date</th><th>Time</th><th>Type</th><th>Status</th><th>Requested</th></tr></thead>
                        <tbody id="tbodyTours"></tbody>
                    </table>
                </div>
                <div class="report-pagination" id="paginationTours"></div>
            </div>

            <!-- ========== COMMISSION TAB ========== -->
            <div class="tab-pane fade" id="content-commission" role="tabpanel">
                <!-- PERFORMANCE TREND -->
                <div class="trend-filter-bar">
                    <div class="trend-filter-group"><label>Time Period</label><select class="filter-select" id="trendPeriod"><option value="monthly" selected>Monthly</option><option value="weekly">Weekly</option><option value="yearly">Yearly</option><option value="custom">Custom Range</option></select></div>
                    <div class="trend-filter-group" id="trendDateFromGroup" style="display:none;"><label>From</label><input type="date" class="filter-input" id="trendDateFrom"></div>
                    <div class="trend-filter-group" id="trendDateToGroup" style="display:none;"><label>To</label><input type="date" class="filter-input" id="trendDateTo"></div>
                    <div class="trend-filter-actions"><button class="btn-gold-sm" id="applyTrendBtn"><i class="bi bi-arrow-clockwise"></i> Update</button></div>
                </div>
                <div class="chart-grid chart-grid-2" style="margin-bottom:1.5rem;">
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-graph-up"></i> Closed Deals Over Time</div></div>
                        <div class="chart-container h-280"><canvas id="chartPerformanceTrend"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-wallet2"></i> Commission by Type</div></div>
                        <div class="chart-container h-280"><canvas id="chartCommType"></canvas></div>
                    </div>
                </div>
                <div class="report-table-wrapper" style="max-height:500px;overflow-y:auto;">
                    <table class="report-table" id="tableCommission">
                        <thead><tr><th>#</th><th>Type</th><th>Property</th><th>City</th><th>Prop. Type</th><th>Client</th><th>Transaction Value</th><th>Commission</th><th>Rate</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody id="tbodyCommission"></tbody>
                    </table>
                </div>
                <div class="report-pagination" id="paginationCommission"></div>
            </div>

            <!-- ========== INSIGHTS TAB ========== -->
            <div class="tab-pane fade" id="content-insights" role="tabpanel">

                <!-- Revenue Trend + Commission Pipeline -->
                <div class="chart-grid chart-grid-2" style="margin-bottom:1.5rem;">
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-graph-up-arrow"></i> Monthly Revenue Trend (12 Months)</div></div>
                        <div class="chart-container h-280"><canvas id="chartRevenueTrend"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-funnel-fill"></i> Commission Pipeline</div></div>
                        <div class="chart-container h-280"><canvas id="chartCommPipeline"></canvas></div>
                    </div>
                </div>

                <!-- Price Range Distribution + Price Analysis -->
                <div class="chart-grid chart-grid-2" style="margin-bottom:1.5rem;">
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-tags-fill"></i> Price Range Distribution</div></div>
                        <div class="chart-container h-250"><canvas id="chartPriceRange"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="bi bi-trophy-fill"></i> Most Toured Properties</div></div>
                        <div class="chart-container h-250"><canvas id="chartMostToured"></canvas></div>
                    </div>
                </div>

                <!-- Property Performance Ranking -->
                <h6 style="color:var(--gold);font-weight:700;font-size:0.85rem;margin:1.5rem 0 0.75rem;text-transform:uppercase;letter-spacing:0.8px;"><i class="bi bi-star-fill me-2"></i>Property Performance Ranking</h6>
                <div class="report-table-wrapper" style="max-height:400px;overflow-y:auto;margin-bottom:1.5rem;">
                    <table class="report-table" id="tablePropertyPerf">
                        <thead><tr><th>#</th><th>Property</th><th>City</th><th>Type</th><th>Price</th><th>Views</th><th>Likes</th><th>Tours</th><th>Completed</th><th>DOM</th><th>Status</th><th>Score</th></tr></thead>
                        <tbody id="tbodyPropertyPerf"></tbody>
                    </table>
                </div>

                <!-- Lease Health Monitor -->
                <h6 style="color:var(--gold);font-weight:700;font-size:0.85rem;margin:1.5rem 0 0.75rem;text-transform:uppercase;letter-spacing:0.8px;"><i class="bi bi-heart-pulse-fill me-2"></i>Lease Health Monitor</h6>
                <div class="report-table-wrapper" style="max-height:400px;overflow-y:auto;margin-bottom:1.5rem;">
                    <table class="report-table" id="tableLeaseHealth">
                        <thead><tr><th>#</th><th>Property</th><th>City</th><th>Tenant</th><th>Rent</th><th>Lease End</th><th>Days Left</th><th>Urgency</th><th>Confirmed</th><th>Pending</th><th>Collection %</th><th>Status</th></tr></thead>
                        <tbody id="tbodyLeaseHealth"></tbody>
                    </table>
                </div>

                <!-- Price Negotiation Analysis -->
                <h6 style="color:var(--gold);font-weight:700;font-size:0.85rem;margin:1.5rem 0 0.75rem;text-transform:uppercase;letter-spacing:0.8px;"><i class="bi bi-arrow-left-right me-2"></i>Price Negotiation Analysis</h6>
                <div class="report-table-wrapper" style="max-height:400px;overflow-y:auto;margin-bottom:1.5rem;">
                    <table class="report-table" id="tablePriceAnalysis">
                        <thead><tr><th>#</th><th>Property</th><th>City</th><th>Type</th><th>Listing Price</th><th>Sale Price</th><th>Difference</th><th>Diff %</th><th>Sale Date</th></tr></thead>
                        <tbody id="tbodyPriceAnalysis"></tbody>
                    </table>
                </div>

                <!-- Stale Listings Alert -->
                <h6 style="color:var(--gold);font-weight:700;font-size:0.85rem;margin:1.5rem 0 0.75rem;text-transform:uppercase;letter-spacing:0.8px;"><i class="bi bi-exclamation-triangle-fill me-2"></i>Stale Listings Alert <span style="color:var(--gray-500);font-weight:500;font-size:0.75rem;text-transform:none;letter-spacing:0;">&mdash; Properties with low engagement</span></h6>
                <div class="report-table-wrapper" style="max-height:350px;overflow-y:auto;">
                    <table class="report-table" id="tableStaleListings">
                        <thead><tr><th>#</th><th>Property</th><th>City</th><th>Type</th><th>Price</th><th>Views</th><th>Likes</th><th>Tours</th><th>Days on Market</th><th>Status</th></tr></thead>
                        <tbody id="tbodyStaleListings"></tbody>
                    </table>
                </div>

            </div>

        </div>
    </div>

    </div><!-- /#page-content -->
</div><!-- /.dashboard-content -->

<!-- FILTER SIDEBAR -->
<div class="filter-sidebar" id="filterSidebar">
    <div class="filter-sidebar-overlay" id="filterOverlay"></div>
    <div class="filter-sidebar-content">
        <div class="filter-header">
            <h4><i class="bi bi-funnel-fill"></i> Smart Report Filters</h4>
            <button class="btn-close-filter" id="closeFilterBtn"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="filter-body">
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-calendar3"></i> Date Range</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div><label style="font-size:0.75rem;color:var(--gray-500);display:block;margin-bottom:0.25rem;">From</label><input type="date" class="filter-input" id="filterDateFrom"></div>
                    <div><label style="font-size:0.75rem;color:var(--gray-500);display:block;margin-bottom:0.25rem;">To</label><input type="date" class="filter-input" id="filterDateTo"></div>
                </div>
            </div>
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-house-door"></i> Property Type</div>
                <div class="filter-chips">
                    <?php foreach($property_types as $pt): ?>
                    <label class="filter-chip"><input type="checkbox" class="filter-prop-type" value="<?= htmlspecialchars($pt) ?>" checked> <span><?= htmlspecialchars($pt) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-geo-alt"></i> City</div>
                <select class="filter-select" id="filterCity"><option value="">All Cities</option>
                    <?php foreach($cities as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-tag"></i> Listing Status</div>
                <div class="filter-chips">
                    <label class="filter-chip"><input type="checkbox" class="filter-status" value="For Sale" checked> <span>For Sale</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-status" value="For Rent" checked> <span>For Rent</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-status" value="Pending Sold" checked> <span>Pending Sold</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-status" value="Sold" checked> <span>Sold</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-status" value="Rented" checked> <span>Rented</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-status" value="Pending Rented" checked> <span>Pending Rented</span></label>
                </div>
            </div>
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-shield-check"></i> Approval Status</div>
                <div class="filter-chips">
                    <label class="filter-chip"><input type="checkbox" class="filter-approval" value="pending" checked> <span>Pending</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-approval" value="approved" checked> <span>Approved</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-approval" value="rejected" checked> <span>Rejected</span></label>
                </div>
            </div>
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-calendar-check"></i> Tour Status</div>
                <div class="filter-chips">
                    <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Pending" checked> <span>Pending</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Confirmed" checked> <span>Confirmed</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Completed" checked> <span>Completed</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Cancelled" checked> <span>Cancelled</span></label>
                    <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Rejected" checked> <span>Rejected</span></label>
                </div>
            </div>
            <div class="filter-results-summary"><i class="bi bi-check-circle-fill"></i><div><div class="filter-results-count" id="filteredCount">0</div><div class="filter-results-label">Records Match Your Filters</div></div></div>
        </div>
        <div class="filter-footer">
            <button class="btn btn-outline-secondary" id="clearFiltersBtn"><i class="bi bi-arrow-clockwise"></i> Reset All</button>
            <button class="btn btn-primary" id="applyFiltersBtn"><i class="bi bi-check2"></i> Apply Filters</button>
        </div>
    </div>
</div>

<!-- EXPORT PREVIEW MODAL -->
<div class="export-modal-overlay" id="exportModalOverlay">
    <div class="export-modal">
        <div class="export-modal-header">
            <div style="display:flex;align-items:center;gap:0.75rem;"><img src="../images/Logo.png" alt="Logo" style="height:28px;width:auto;"><span style="font-weight:700;font-size:1rem;" id="exportModalTitle">Export Preview</span></div>
            <button class="btn-close-filter" id="closeExportModal"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="export-modal-body" id="exportPreviewBody"></div>
        <div class="export-modal-footer">
            <div class="export-info"><strong id="exportRowCount">0</strong> records &mdash; <span id="exportTabName"></span></div>
            <div style="display:flex;gap:0.75rem;">
                <button class="btn btn-outline-secondary" id="cancelExportBtn" style="border:1px solid rgba(255,255,255,0.1);background:transparent;color:var(--gray-400);padding:0.6rem 1.25rem;font-size:0.85rem;font-weight:600;border-radius:4px;cursor:pointer;">Cancel</button>
                <button class="btn-export btn-export-pdf" id="confirmExportPDF" style="display:none;"><i class="bi bi-file-earmark-pdf"></i> Download PDF</button>
                <button class="btn-export btn-export-excel" id="confirmExportExcel" style="display:none;"><i class="bi bi-file-earmark-spreadsheet"></i> Download Excel</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<!-- Logout Modal -->
<?php include '../logout_modal.php'; ?>

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>

<script>
(function() {
'use strict';

// =============================================
// DATA FROM PHP
// =============================================
var DATA = {
    properties: <?= json_encode($property_report) ?>,
    sales: <?= json_encode($sales_report) ?>,
    rentals: <?= json_encode($rental_report) ?>,
    tours: <?= json_encode($tour_report) ?>,
    commission: <?= json_encode($commission_report) ?>,
    performanceEvents: <?= json_encode($performance_events) ?>,
    propByType: <?= json_encode($prop_by_type) ?>,
    propByCity: <?= json_encode($prop_by_city) ?>,
    salesByMonth: <?= json_encode($sales_by_month) ?>,
    tourStatuses: <?= json_encode($tour_statuses) ?>,
    expiringLeases: <?= json_encode($expiring_leases) ?>,
    commissionPipeline: <?= json_encode($commission_pipeline) ?>,
    monthlyRevenue: <?= json_encode($monthly_revenue) ?>,
    tourMonthly: <?= json_encode($tour_monthly) ?>,
    priceComparisons: <?= json_encode($price_comparisons) ?>,
    leaseStatusDist: <?= json_encode($lease_status_dist) ?>,
    mostToured: <?= json_encode($most_toured) ?>,
    priceRanges: <?= json_encode($price_ranges) ?>
};

var FILTERED = {
    properties: DATA.properties.slice(),
    sales: DATA.sales.slice(),
    rentals: DATA.rentals.slice(),
    tours: DATA.tours.slice(),
    commission: DATA.commission.slice(),
    performanceEvents: DATA.performanceEvents.slice(),
    insights: []
};

var ROWS_PER_PAGE = 25;
var currentPages = { properties:1, sales:1, rentals:1, tours:1, commission:1 };

// =============================================
// COLORS & HELPERS
// =============================================
var COLORS = {
    gold:'#d4af37', blue:'#2563eb', green:'#22c55e', red:'#ef4444',
    orange:'#f59e0b', cyan:'#06b6d4', purple:'#a855f7', slate:'#94a3b8',
    palette:['#2563eb','#d4af37','#22c55e','#ef4444','#06b6d4','#a855f7','#f59e0b','#ec4899','#94a3b8','#059669']
};

function fmtPrice(v) { return '\u20B1' + Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:0,maximumFractionDigits:0}); }
function fmtPriceExport(v) { return 'PHP ' + Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d) { if(!d) return '\u2014'; return d; }
function esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s))); return d.innerHTML; }
function statusPill(s) {
    if(!s) return '\u2014';
    var cls = s.toLowerCase().replace(/\s+/g,'-');
    return '<span class="status-pill '+esc(cls)+'">'+esc(s)+'</span>';
}
function priceCell(v) { return v ? '<span class="price-text">'+fmtPrice(v)+'</span>' : '\u2014'; }

// =============================================
// TABLE RENDERERS
// =============================================
function renderPropertiesTable(page) {
    var rows = FILTERED.properties, pp = ROWS_PER_PAGE, start = (page-1)*pp, end = Math.min(start+pp, rows.length);
    var h = '';
    for (var i = start; i < end; i++) {
        var r = rows[i];
        var domVal = r.days_on_market || 0;
        var domColor = domVal > 90 ? '#ef4444' : domVal > 45 ? '#f59e0b' : '#22c55e';
        h += '<tr><td>'+(i+1)+'</td><td>'+esc((r.StreetAddress||'')+', '+(r.Barangay||''))+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+(r.Bedrooms||'')+'</td><td>'+(r.Bathrooms||'')+'</td><td>'+priceCell(r.ListingPrice)+'</td><td>'+statusPill(r.Status)+'</td><td>'+statusPill(r.approval_status)+'</td><td>'+(r.ViewsCount||0)+'</td><td>'+(r.Likes||0)+'</td><td>'+(r.tour_count||0)+'</td><td><span style="color:'+domColor+';font-weight:700;">'+domVal+'</span></td><td>'+fmtDate(r.ListingDate)+'</td></tr>';
    }
    document.getElementById('tbodyProperties').innerHTML = h || '<tr><td colspan="14" class="empty-state"><i class="bi bi-inbox"></i><h4>No properties found</h4></td></tr>';
    renderPagination('paginationProperties', rows.length, page, 'properties');
}

function renderSalesTable(page) {
    var rows = FILTERED.sales, pp = ROWS_PER_PAGE, start = (page-1)*pp, end = Math.min(start+pp, rows.length);
    var h = '';
    for (var i = start; i < end; i++) {
        var r = rows[i];
        var listP = parseFloat(r.listing_price)||0, saleP = parseFloat(r.final_sale_price)||0;
        var diffPct = listP > 0 ? (((saleP - listP) / listP) * 100).toFixed(1) : '\u2014';
        var diffColor = diffPct === '\u2014' ? '' : (parseFloat(diffPct) >= 0 ? 'color:#22c55e;' : 'color:#ef4444;');
        var diffStr = diffPct === '\u2014' ? '\u2014' : (parseFloat(diffPct) >= 0 ? '+' : '') + diffPct + '%';
        h += '<tr><td>'+(i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+esc(r.buyer_name)+'</td><td>'+priceCell(r.listing_price)+'</td><td>'+priceCell(r.final_sale_price)+'</td><td><span style="font-weight:700;'+diffColor+'">'+diffStr+'</span></td><td>'+fmtDate(r.sale_date)+'</td><td>'+priceCell(r.commission_amount)+'</td><td>'+(r.commission_percentage?esc(r.commission_percentage)+'%':'\u2014')+'</td><td>'+statusPill(r.commission_status)+'</td></tr>';
    }
    document.getElementById('tbodySales').innerHTML = h || '<tr><td colspan="12" class="empty-state"><i class="bi bi-inbox"></i><h4>No sales found</h4></td></tr>';
    renderPagination('paginationSales', rows.length, page, 'sales');
}

function renderRentalsTable(page) {
    var rows = FILTERED.rentals, pp = ROWS_PER_PAGE, start = (page-1)*pp, end = Math.min(start+pp, rows.length);
    var h = '';
    for (var i = start; i < end; i++) {
        var r = rows[i];
        h += '<tr><td>'+(i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+esc(r.tenant_name)+'</td><td>'+priceCell(r.monthly_rent)+'</td><td>'+priceCell(r.security_deposit)+'</td><td>'+fmtDate(r.lease_start_date)+'</td><td>'+fmtDate(r.lease_end_date)+'</td><td>'+(r.lease_term_months?r.lease_term_months+' mo':'\u2014')+'</td><td>'+priceCell(r.total_collected)+'</td><td>'+priceCell(r.total_commission)+'</td><td>'+((r.confirmed_payments||0)+'/'+(parseInt(r.confirmed_payments||0)+parseInt(r.pending_payments||0)))+'</td><td>'+statusPill(r.lease_status||'Active')+'</td></tr>';
    }
    document.getElementById('tbodyRentals').innerHTML = h || '<tr><td colspan="14" class="empty-state"><i class="bi bi-inbox"></i><h4>No rentals found</h4></td></tr>';
    renderPagination('paginationRentals', rows.length, page, 'rentals');
}

function renderToursTable(page) {
    var rows = FILTERED.tours, pp = ROWS_PER_PAGE, start = (page-1)*pp, end = Math.min(start+pp, rows.length);
    var h = '';
    for (var i = start; i < end; i++) {
        var r = rows[i];
        h += '<tr><td>'+(i+1)+'</td><td>'+esc(r.user_name)+'</td><td>'+esc(r.user_email)+'</td><td>'+esc(r.user_phone)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+fmtDate(r.tour_date)+'</td><td>'+esc(r.tour_time)+'</td><td>'+esc(r.tour_type)+'</td><td>'+statusPill(r.request_status)+'</td><td>'+fmtDate(r.requested_at)+'</td></tr>';
    }
    document.getElementById('tbodyTours').innerHTML = h || '<tr><td colspan="11" class="empty-state"><i class="bi bi-inbox"></i><h4>No tour requests found</h4></td></tr>';
    renderPagination('paginationTours', rows.length, page, 'tours');
}

function renderCommissionTable(page) {
    var rows = FILTERED.commission, pp = ROWS_PER_PAGE, start = (page-1)*pp, end = Math.min(start+pp, rows.length);
    var h = '';
    for (var i = start; i < end; i++) {
        var r = rows[i];
        h += '<tr><td>'+(i+1)+'</td><td>'+statusPill(r.type)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+esc(r.client_name)+'</td><td>'+priceCell(r.transaction_value)+'</td><td>'+priceCell(r.commission_amount)+'</td><td>'+(r.commission_percentage?esc(r.commission_percentage)+'%':'\u2014')+'</td><td>'+statusPill(r.status)+'</td><td>'+fmtDate(r.event_date)+'</td></tr>';
    }
    document.getElementById('tbodyCommission').innerHTML = h || '<tr><td colspan="11" class="empty-state"><i class="bi bi-inbox"></i><h4>No commission records found</h4></td></tr>';
    renderPagination('paginationCommission', rows.length, page, 'commission');
}

// =============================================
// INSIGHTS TAB RENDERERS
// =============================================
function renderPropertyPerformanceTable() {
    var rows = FILTERED.properties.slice().map(function(r) {
        var score = (parseInt(r.ViewsCount)||0) + (parseInt(r.Likes)||0)*3 + (parseInt(r.tour_count)||0)*10 + (parseInt(r.completed_tour_count)||0)*20;
        return Object.assign({}, r, { score: score });
    }).sort(function(a,b) { return b.score - a.score; });

    var h = '';
    rows.forEach(function(r, i) {
        var domVal = r.days_on_market || 0;
        var domColor = domVal > 90 ? '#ef4444' : domVal > 45 ? '#f59e0b' : '#22c55e';
        var scoreColor = r.score > 100 ? '#22c55e' : r.score > 30 ? '#f59e0b' : '#ef4444';
        h += '<tr><td>'+(i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+priceCell(r.ListingPrice)+'</td><td>'+(r.ViewsCount||0)+'</td><td>'+(r.Likes||0)+'</td><td>'+(r.tour_count||0)+'</td><td>'+(r.completed_tour_count||0)+'</td><td><span style="color:'+domColor+';font-weight:700;">'+domVal+'</span></td><td>'+statusPill(r.Status)+'</td><td><span style="color:'+scoreColor+';font-weight:800;">'+r.score+'</span></td></tr>';
    });
    document.getElementById('tbodyPropertyPerf').innerHTML = h || '<tr><td colspan="12" class="empty-state"><i class="bi bi-inbox"></i><h4>No property data</h4></td></tr>';
}

function renderLeaseHealthTable() {
    var cityFilter = document.getElementById('filterCity').value;
    var rows = DATA.expiringLeases.filter(function(r) { if (cityFilter && r.City !== cityFilter) return false; return true; });
    var h = '';
    rows.forEach(function(r, i) {
        var days = parseInt(r.days_remaining) || 0;
        var urgency = '', urgencyColor = '';
        if (days < 0) { urgency = 'EXPIRED'; urgencyColor = '#ef4444'; }
        else if (days <= 30) { urgency = 'CRITICAL'; urgencyColor = '#ef4444'; }
        else if (days <= 60) { urgency = 'WARNING'; urgencyColor = '#f59e0b'; }
        else if (days <= 90) { urgency = 'WATCH'; urgencyColor = '#06b6d4'; }
        else { urgency = 'OK'; urgencyColor = '#22c55e'; }
        var totalP = parseInt(r.total_pmts)||0, confirmedP = parseInt(r.confirmed_pmts)||0, pendingP = parseInt(r.pending_pmts)||0;
        var collPct = totalP > 0 ? Math.round((confirmedP / totalP) * 100) : 0;
        var collColor = collPct >= 80 ? '#22c55e' : collPct >= 50 ? '#f59e0b' : '#ef4444';
        h += '<tr><td>'+(i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.tenant_name)+'</td><td>'+priceCell(r.monthly_rent)+'</td><td>'+fmtDate(r.lease_end_date)+'</td><td><span style="color:'+urgencyColor+';font-weight:700;">'+days+'</span></td><td><span class="status-pill" style="background:'+urgencyColor+'22;color:'+urgencyColor+';border:1px solid '+urgencyColor+'44;">'+urgency+'</span></td><td>'+confirmedP+'</td><td style="color:'+(pendingP>0?'#f59e0b':'var(--gray-400)')+';">'+pendingP+'</td><td><span style="color:'+collColor+';font-weight:700;">'+collPct+'%</span></td><td>'+statusPill(r.lease_status)+'</td></tr>';
    });
    document.getElementById('tbodyLeaseHealth').innerHTML = h || '<tr><td colspan="12" class="empty-state"><i class="bi bi-inbox"></i><h4>No active leases</h4></td></tr>';
}

function renderPriceAnalysisTable() {
    var cityFilter = document.getElementById('filterCity').value;
    var rows = DATA.priceComparisons.filter(function(r) { if (cityFilter && r.City !== cityFilter) return false; return true; });
    var h = '';
    rows.forEach(function(r, i) {
        var listP = parseFloat(r.ListingPrice)||0, saleP = parseFloat(r.final_sale_price)||0;
        var diff = saleP - listP;
        var diffPct = r.price_diff_pct != null ? parseFloat(r.price_diff_pct) : 0;
        var diffColor = diffPct >= 0 ? '#22c55e' : '#ef4444';
        var diffSign = diffPct >= 0 ? '+' : '';
        h += '<tr><td>'+(i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+priceCell(r.ListingPrice)+'</td><td>'+priceCell(r.final_sale_price)+'</td><td><span style="color:'+diffColor+';font-weight:700;">'+fmtPrice(Math.abs(diff))+'</span></td><td><span style="color:'+diffColor+';font-weight:700;">'+diffSign+diffPct.toFixed(1)+'%</span></td><td>'+fmtDate(r.sale_date)+'</td></tr>';
    });
    document.getElementById('tbodyPriceAnalysis').innerHTML = h || '<tr><td colspan="9" class="empty-state"><i class="bi bi-inbox"></i><h4>No sales data for comparison</h4></td></tr>';
}

function renderStaleListingsTable() {
    var stale = FILTERED.properties.filter(function(r) {
        if (!r.approval_status || r.approval_status !== 'approved') return false;
        if (['Sold','Rented','Pending Sold','Pending Rented'].indexOf(r.Status) >= 0) return false;
        return (r.days_on_market || 0) >= 30 && (parseInt(r.tour_count)||0) <= 1;
    }).sort(function(a,b) { return (b.days_on_market||0) - (a.days_on_market||0); });

    var h = '';
    stale.forEach(function(r, i) {
        var domColor = (r.days_on_market||0) > 90 ? '#ef4444' : '#f59e0b';
        h += '<tr><td>'+(i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+priceCell(r.ListingPrice)+'</td><td>'+(r.ViewsCount||0)+'</td><td>'+(r.Likes||0)+'</td><td>'+(r.tour_count||0)+'</td><td><span style="color:'+domColor+';font-weight:700;">'+(r.days_on_market||0)+' days</span></td><td>'+statusPill(r.Status)+'</td></tr>';
    });
    document.getElementById('tbodyStaleListings').innerHTML = h || '<tr><td colspan="10" class="empty-state"><i class="bi bi-check-circle" style="color:#22c55e !important;"></i><h4>No stale listings!</h4><p style="color:var(--gray-500);font-size:0.82rem;margin-top:0.25rem;">All your properties are getting engagement.</p></td></tr>';
}

function renderPagination(containerId, total, currentPage, tabKey) {
    var pp = ROWS_PER_PAGE, totalPages = Math.ceil(total/pp);
    if (totalPages <= 1) { document.getElementById(containerId).innerHTML = '<div class="pagination-info">Showing '+total+' record'+(total!==1?'s':'')+'</div>'; return; }
    var start = (currentPage-1)*pp+1, end = Math.min(currentPage*pp, total);
    var h = '<div class="pagination-info">Showing '+start+'\u2013'+end+' of '+total+'</div><div class="pagination-controls">';
    h += '<button class="page-btn" data-page="'+(currentPage-1)+'" data-tab="'+tabKey+'"'+(currentPage<=1?' disabled':'')+'>\u2039</button>';
    for (var p = Math.max(1,currentPage-2); p <= Math.min(totalPages,currentPage+2); p++) {
        h += '<button class="page-btn'+(p===currentPage?' active':'')+'" data-page="'+p+'" data-tab="'+tabKey+'">'+p+'</button>';
    }
    h += '<button class="page-btn" data-page="'+(currentPage+1)+'" data-tab="'+tabKey+'"'+(currentPage>=totalPages?' disabled':'')+'>\u203A</button></div>';
    document.getElementById(containerId).innerHTML = h;
}

// Pagination click handler
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.page-btn');
    if (!btn || btn.disabled) return;
    var page = parseInt(btn.dataset.page), tab = btn.dataset.tab;
    if (isNaN(page) || page < 1) return;
    currentPages[tab] = page;
    var renderers = { properties:renderPropertiesTable, sales:renderSalesTable, rentals:renderRentalsTable, tours:renderToursTable, commission:renderCommissionTable };
    if (renderers[tab]) renderers[tab](page);
});

// =============================================
// CHART SETUP
// =============================================
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#94a3b8';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.padding = 12;
Chart.defaults.elements.bar.borderRadius = 4;
Chart.defaults.elements.bar.borderSkipped = false;
Chart.defaults.elements.line.tension = 0.4;

var chartInstances = {};

function renderCharts() {
    // Properties by Type (Doughnut)
    var ptLabels = Object.keys(DATA.propByType), ptData = Object.values(DATA.propByType);
    if (chartInstances.propType) chartInstances.propType.destroy();
    if (ptLabels.length > 0) {
        chartInstances.propType = new Chart(document.getElementById('chartPropType'), {
            type: 'doughnut',
            data: { labels: ptLabels, datasets: [{ data: ptData, backgroundColor: COLORS.palette.slice(0, ptLabels.length), borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { color: '#94a3b8', font: { size: 11 } } } }, cutout: '65%' }
        });
    }

    // Listing Status (Bar)
    var statuses = {}, props = DATA.properties;
    props.forEach(function(p) { var s = p.Status || 'Unknown'; statuses[s] = (statuses[s]||0) + 1; });
    var stLabels = Object.keys(statuses), stData = Object.values(statuses);
    var stColors = stLabels.map(function(s) {
        if (s.indexOf('Sale')>=0) return COLORS.blue;
        if (s.indexOf('Rent')>=0) return COLORS.gold;
        if (s==='Sold') return COLORS.slate;
        if (s.indexOf('Pending')>=0) return COLORS.orange;
        return COLORS.cyan;
    });
    if (chartInstances.listingStatus) chartInstances.listingStatus.destroy();
    if (stLabels.length > 0) {
        chartInstances.listingStatus = new Chart(document.getElementById('chartListingStatus'), {
            type: 'bar',
            data: { labels: stLabels, datasets: [{ data: stData, backgroundColor: stColors, borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8' } }, x: { grid: { display: false }, ticks: { color: '#94a3b8' } } } }
        });
    }

    // Properties by City (Horizontal Bar)
    var pcLabels = Object.keys(DATA.propByCity), pcData = Object.values(DATA.propByCity);
    if (chartInstances.propCity) chartInstances.propCity.destroy();
    if (pcLabels.length > 0) {
        chartInstances.propCity = new Chart(document.getElementById('chartPropCity'), {
            type: 'bar',
            data: { labels: pcLabels, datasets: [{ data: pcData, backgroundColor: COLORS.palette.slice(0, pcLabels.length), borderWidth: 0 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8' } }, y: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 10 } } } } }
        });
    }

    // Sales by Month (Bar)
    var smLabels = DATA.salesByMonth.map(function(m){return m.month_label;}), smData = DATA.salesByMonth.map(function(m){return parseFloat(m.revenue)||0;});
    if (chartInstances.salesMonthly) chartInstances.salesMonthly.destroy();
    if (smLabels.length > 0) {
        chartInstances.salesMonthly = new Chart(document.getElementById('chartSalesMonthly'), {
            type: 'bar',
            data: { labels: smLabels, datasets: [{ label: 'Revenue', data: smData, backgroundColor: 'rgba(212,175,55,0.6)', borderColor: '#d4af37', borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', callback: function(v){ return '\u20B1'+Number(v).toLocaleString(); } } }, x: { grid: { display: false }, ticks: { color: '#94a3b8' } } } }
        });
    }

    // Price Comparison (Listing vs Sale) - Grouped Bar
    if (chartInstances.priceComparison) chartInstances.priceComparison.destroy();
    if (DATA.priceComparisons.length > 0) {
        var pcmpLabels = DATA.priceComparisons.slice(0,10).map(function(r){ return (r.StreetAddress||'').substring(0,20); });
        var pcmpList = DATA.priceComparisons.slice(0,10).map(function(r){ return parseFloat(r.ListingPrice)||0; });
        var pcmpSale = DATA.priceComparisons.slice(0,10).map(function(r){ return parseFloat(r.final_sale_price)||0; });
        chartInstances.priceComparison = new Chart(document.getElementById('chartPriceComparison'), {
            type: 'bar',
            data: { labels: pcmpLabels, datasets: [
                { label: 'Listing Price', data: pcmpList, backgroundColor: 'rgba(37,99,235,0.5)', borderColor: '#2563eb', borderWidth: 1 },
                { label: 'Sale Price', data: pcmpSale, backgroundColor: 'rgba(212,175,55,0.5)', borderColor: '#d4af37', borderWidth: 1 }
            ]},
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8', font: { size: 10 } } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', callback: function(v){ return '\u20B1'+Number(v/1000000).toFixed(1)+'M'; } } }, x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 9 }, maxRotation: 45 } } } }
        });
    }

    // Tour Status (Doughnut)
    var tsLabels = Object.keys(DATA.tourStatuses), tsData = Object.values(DATA.tourStatuses);
    var tsColors = tsLabels.map(function(s) {
        if (s==='Pending') return COLORS.orange;
        if (s==='Confirmed') return COLORS.blue;
        if (s==='Completed') return COLORS.green;
        if (s==='Cancelled') return COLORS.red;
        if (s==='Rejected') return '#94a3b8';
        return COLORS.cyan;
    });
    if (chartInstances.tourStatus) chartInstances.tourStatus.destroy();
    if (tsLabels.length > 0) {
        chartInstances.tourStatus = new Chart(document.getElementById('chartTourStatus'), {
            type: 'doughnut',
            data: { labels: tsLabels, datasets: [{ data: tsData, backgroundColor: tsColors, borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { color: '#94a3b8', font: { size: 11 } } } }, cutout: '65%' }
        });
    }

    // Tour Monthly Trend (Line)
    if (chartInstances.tourMonthly) chartInstances.tourMonthly.destroy();
    if (DATA.tourMonthly.length > 0) {
        var tmLabels = DATA.tourMonthly.map(function(m){return m.ml;});
        var tmTotal = DATA.tourMonthly.map(function(m){return parseInt(m.cnt)||0;});
        var tmCompleted = DATA.tourMonthly.map(function(m){return parseInt(m.completed)||0;});
        chartInstances.tourMonthly = new Chart(document.getElementById('chartTourMonthly'), {
            type: 'line',
            data: { labels: tmLabels, datasets: [
                { label: 'Total Requests', data: tmTotal, borderColor: COLORS.blue, backgroundColor: 'rgba(37,99,235,0.1)', fill: true, pointRadius: 3, pointBackgroundColor: COLORS.blue },
                { label: 'Completed', data: tmCompleted, borderColor: COLORS.green, backgroundColor: 'rgba(34,197,94,0.1)', fill: true, pointRadius: 3, pointBackgroundColor: COLORS.green }
            ]},
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8', font: { size: 10 } } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', stepSize: 1 } }, x: { grid: { display: false }, ticks: { color: '#94a3b8', maxRotation: 45, font: { size: 9 } } } } }
        });
    }

    // Lease Status Distribution (Doughnut)
    var lsLabels = Object.keys(DATA.leaseStatusDist), lsData = Object.values(DATA.leaseStatusDist);
    var lsColors = lsLabels.map(function(s) {
        if (s==='Active') return COLORS.green;
        if (s==='Renewed') return COLORS.blue;
        if (s==='Terminated') return COLORS.red;
        if (s==='Expired') return '#94a3b8';
        return COLORS.orange;
    });
    if (chartInstances.leaseStatus) chartInstances.leaseStatus.destroy();
    if (lsLabels.length > 0) {
        chartInstances.leaseStatus = new Chart(document.getElementById('chartLeaseStatus'), {
            type: 'doughnut',
            data: { labels: lsLabels, datasets: [{ data: lsData, backgroundColor: lsColors, borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { color: '#94a3b8', font: { size: 11 } } } }, cutout: '65%' }
        });
    }

    // Monthly Rental Collections (Bar)
    if (chartInstances.rentalCollections) chartInstances.rentalCollections.destroy();
    var rcLabels = DATA.monthlyRevenue.map(function(m){return m.month_label;});
    var rcData = DATA.monthlyRevenue.map(function(m){return m.rental_revenue;});
    if (rcLabels.length > 0) {
        chartInstances.rentalCollections = new Chart(document.getElementById('chartRentalCollections'), {
            type: 'bar',
            data: { labels: rcLabels, datasets: [{ label: 'Collections', data: rcData, backgroundColor: 'rgba(37,99,235,0.5)', borderColor: '#2563eb', borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', callback: function(v){ return '\u20B1'+Number(v).toLocaleString(); } } }, x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 9 }, maxRotation: 45 } } } }
        });
    }

    // Commission by type (Doughnut)
    var saleComm = 0, rentalComm = 0;
    DATA.commission.forEach(function(c) { if (c.type==='Sale') saleComm += parseFloat(c.commission_amount)||0; else rentalComm += parseFloat(c.commission_amount)||0; });
    if (chartInstances.commType) chartInstances.commType.destroy();
    if (saleComm > 0 || rentalComm > 0) {
        chartInstances.commType = new Chart(document.getElementById('chartCommType'), {
            type: 'doughnut',
            data: { labels: ['Sales Commission','Rental Commission'], datasets: [{ data: [saleComm, rentalComm], backgroundColor: [COLORS.gold, COLORS.blue], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { color: '#94a3b8', font: { size: 11 } } } }, cutout: '65%' }
        });
    }

    // =============================================
    // INSIGHTS TAB CHARTS
    // =============================================

    // Revenue Trend (Stacked Bar: Sales + Rental)
    if (chartInstances.revenueTrend) chartInstances.revenueTrend.destroy();
    var rvLabels = DATA.monthlyRevenue.map(function(m){return m.month_label;});
    var rvSales = DATA.monthlyRevenue.map(function(m){return m.sales_revenue;});
    var rvRental = DATA.monthlyRevenue.map(function(m){return m.rental_revenue;});
    chartInstances.revenueTrend = new Chart(document.getElementById('chartRevenueTrend'), {
        type: 'bar',
        data: { labels: rvLabels, datasets: [
            { label: 'Sales Revenue', data: rvSales, backgroundColor: 'rgba(212,175,55,0.6)', borderColor: '#d4af37', borderWidth: 1 },
            { label: 'Rental Collections', data: rvRental, backgroundColor: 'rgba(37,99,235,0.5)', borderColor: '#2563eb', borderWidth: 1 }
        ]},
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8' } } }, scales: { x: { stacked: true, grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 9 }, maxRotation: 45 } }, y: { stacked: true, beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', callback: function(v){ return '\u20B1'+Number(v/1000000).toFixed(1)+'M'; } } } } }
    });

    // Commission Pipeline (Horizontal Bar)
    if (chartInstances.commPipeline) chartInstances.commPipeline.destroy();
    var cpData = DATA.commissionPipeline;
    chartInstances.commPipeline = new Chart(document.getElementById('chartCommPipeline'), {
        type: 'bar',
        data: { labels: ['Pending', 'Calculated', 'Paid', 'Cancelled'], datasets: [{ data: [cpData.pending, cpData.calculated, cpData.paid, cpData.cancelled], backgroundColor: [COLORS.orange, COLORS.blue, COLORS.green, COLORS.red], borderWidth: 0 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return fmtPrice(ctx.raw); } } } }, scales: { x: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', callback: function(v){ return '\u20B1'+Number(v).toLocaleString(); } } }, y: { grid: { display: false }, ticks: { color: '#94a3b8', font: { weight: 'bold' } } } } }
    });

    // Price Range Distribution (Bar)
    if (chartInstances.priceRange) chartInstances.priceRange.destroy();
    var prLabels = Object.keys(DATA.priceRanges), prData = Object.values(DATA.priceRanges);
    chartInstances.priceRange = new Chart(document.getElementById('chartPriceRange'), {
        type: 'bar',
        data: { labels: prLabels, datasets: [{ data: prData, backgroundColor: [COLORS.green, COLORS.blue, COLORS.gold, COLORS.purple, COLORS.red], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', stepSize: 1 } }, x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 10 } } } } }
    });

    // Most Toured Properties (Horizontal Bar)
    if (chartInstances.mostToured) chartInstances.mostToured.destroy();
    if (DATA.mostToured.length > 0) {
        var mtLabels = DATA.mostToured.map(function(r){ return (r.StreetAddress||'').substring(0,25); });
        var mtData = DATA.mostToured.map(function(r){ return parseInt(r.tour_count)||0; });
        var mtCompleted = DATA.mostToured.map(function(r){ return parseInt(r.completed_tours)||0; });
        chartInstances.mostToured = new Chart(document.getElementById('chartMostToured'), {
            type: 'bar',
            data: { labels: mtLabels, datasets: [
                { label: 'Total Tours', data: mtData, backgroundColor: 'rgba(37,99,235,0.5)', borderColor: '#2563eb', borderWidth: 1 },
                { label: 'Completed', data: mtCompleted, backgroundColor: 'rgba(34,197,94,0.5)', borderColor: '#22c55e', borderWidth: 1 }
            ]},
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8', font: { size: 10 } } } }, scales: { x: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', stepSize: 1 } }, y: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 9 } } } } }
        });
    }
}

// =============================================
// PERFORMANCE TREND CHART
// =============================================
var performanceTrendChart = null;

function renderPerformanceTrendChart() {
    var period = document.getElementById('trendPeriod').value || 'monthly';
    var fromDate = document.getElementById('trendDateFrom').value || '';
    var toDate = document.getElementById('trendDateTo').value || '';

    var events = DATA.performanceEvents.filter(function(e) {
        if (!e.event_date) return false;
        if (fromDate && e.event_date < fromDate) return false;
        if (toDate && e.event_date > toDate) return false;
        return true;
    });

    var buckets = {};
    events.forEach(function(e) {
        var d = new Date(e.event_date + 'T00:00:00');
        if (isNaN(d.getTime())) return;
        var key = '', label = '';
        if (period === 'weekly') {
            var ws = new Date(d); var day = ws.getDay(); ws.setDate(ws.getDate() - (day===0?6:day-1));
            key = ws.toISOString().slice(0,10);
            label = 'Week of ' + ws.toLocaleDateString('en-PH',{month:'short',day:'numeric'});
        } else if (period === 'yearly') {
            key = String(d.getFullYear()); label = key;
        } else if (period === 'custom') {
            key = e.event_date; label = d.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
        } else {
            key = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
            label = d.toLocaleDateString('en-PH',{month:'short',year:'numeric'});
        }
        if (!buckets[key]) buckets[key] = { key: key, label: label, sold: 0, rented: 0 };
        if (e.transaction_type === 'Sold') buckets[key].sold++; else buckets[key].rented++;
    });

    var sorted = Object.values(buckets).sort(function(a,b){ return a.key < b.key ? -1 : 1; });
    var labels = sorted.map(function(b){return b.label;}), soldData = sorted.map(function(b){return b.sold;}), rentedData = sorted.map(function(b){return b.rented;});

    if (performanceTrendChart) performanceTrendChart.destroy();
    performanceTrendChart = new Chart(document.getElementById('chartPerformanceTrend'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Sold', data: soldData, borderColor: COLORS.gold, backgroundColor: 'rgba(212,175,55,0.1)', fill: true, pointRadius: 4, pointBackgroundColor: COLORS.gold },
                { label: 'Rented', data: rentedData, borderColor: COLORS.blue, backgroundColor: 'rgba(37,99,235,0.1)', fill: true, pointRadius: 4, pointBackgroundColor: COLORS.blue }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8' } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8', stepSize: 1 } }, x: { grid: { display: false }, ticks: { color: '#94a3b8', maxRotation: 45 } } } }
    });
}

document.getElementById('trendPeriod').addEventListener('change', function() {
    var isCustom = this.value === 'custom';
    document.getElementById('trendDateFromGroup').style.display = isCustom ? 'inline-flex' : 'none';
    document.getElementById('trendDateToGroup').style.display = isCustom ? 'inline-flex' : 'none';
    renderPerformanceTrendChart();
});
document.getElementById('applyTrendBtn').addEventListener('click', renderPerformanceTrendChart);

// =============================================
// FILTER SYSTEM
// =============================================
function applyFilters() {
    var dateFrom = document.getElementById('filterDateFrom').value;
    var dateTo = document.getElementById('filterDateTo').value;
    var city = document.getElementById('filterCity').value;
    var ct = []; document.querySelectorAll('.filter-prop-type:checked').forEach(function(c){ct.push(c.value);});
    var cs = []; document.querySelectorAll('.filter-status:checked').forEach(function(c){cs.push(c.value);});
    var ca = []; document.querySelectorAll('.filter-approval:checked').forEach(function(c){ca.push(c.value);});
    var cts = []; document.querySelectorAll('.filter-tour-status:checked').forEach(function(c){cts.push(c.value);});

    FILTERED.properties = DATA.properties.filter(function(r) {
        if (dateFrom && r.ListingDate && r.ListingDate < dateFrom) return false;
        if (dateTo && r.ListingDate && r.ListingDate > dateTo) return false;
        if (city && r.City !== city) return false;
        if (ct.length > 0 && ct.indexOf(r.PropertyType) === -1) return false;
        if (cs.length > 0 && cs.indexOf(r.Status) === -1) return false;
        if (ca.length > 0 && ca.indexOf(r.approval_status) === -1) return false;
        return true;
    });
    FILTERED.sales = DATA.sales.filter(function(r) {
        if (dateFrom && r.sale_date && r.sale_date < dateFrom) return false;
        if (dateTo && r.sale_date && r.sale_date > dateTo) return false;
        if (city && r.City !== city) return false;
        if (ct.length > 0 && ct.indexOf(r.PropertyType) === -1) return false;
        return true;
    });
    FILTERED.rentals = DATA.rentals.filter(function(r) {
        if (dateFrom && r.lease_start_date && r.lease_start_date < dateFrom) return false;
        if (dateTo && r.lease_start_date && r.lease_start_date > dateTo) return false;
        if (city && r.City !== city) return false;
        if (ct.length > 0 && ct.indexOf(r.PropertyType) === -1) return false;
        return true;
    });
    FILTERED.tours = DATA.tours.filter(function(r) {
        if (dateFrom && r.tour_date && r.tour_date < dateFrom) return false;
        if (dateTo && r.tour_date && r.tour_date > dateTo) return false;
        if (city && r.City !== city) return false;
        if (cts.length > 0 && cts.indexOf(r.request_status) === -1) return false;
        return true;
    });
    FILTERED.commission = DATA.commission.filter(function(r) {
        if (dateFrom && r.event_date && r.event_date < dateFrom) return false;
        if (dateTo && r.event_date && r.event_date > dateTo) return false;
        if (city && r.City !== city) return false;
        if (ct.length > 0 && ct.indexOf(r.PropertyType) === -1) return false;
        return true;
    });

    // Filter performance events for Insights tab rebuild
    FILTERED.performanceEvents = DATA.performanceEvents.filter(function(e) {
        if (dateFrom && e.event_date && e.event_date < dateFrom) return false;
        if (dateTo && e.event_date && e.event_date > dateTo) return false;
        if (city && e.City !== city) return false;
        if (ct.length > 0 && ct.indexOf(e.PropertyType) === -1) return false;
        return true;
    });

    currentPages = { properties:1, sales:1, rentals:1, tours:1, commission:1 };
    renderPropertiesTable(1); renderSalesTable(1); renderRentalsTable(1); renderToursTable(1); renderCommissionTable(1);

    // Rebuild Insights tables from filtered data
    rebuildInsightsTables();

    // Update badges
    document.getElementById('badge-properties').textContent = FILTERED.properties.length;
    document.getElementById('badge-sales').textContent = FILTERED.sales.length;
    document.getElementById('badge-rentals').textContent = FILTERED.rentals.length;
    document.getElementById('badge-tours').textContent = FILTERED.tours.length;
    document.getElementById('badge-commission').textContent = FILTERED.commission.length;

    // Smart filter badge count (count active filter categories, not individual values)
    var fc = 0;
    if (dateFrom || dateTo) fc++;
    if (city) fc++;
    if (ct.length < document.querySelectorAll('.filter-prop-type').length) fc++;
    if (cs.length < document.querySelectorAll('.filter-status').length) fc++;
    if (ca.length < document.querySelectorAll('.filter-approval').length) fc++;
    if (cts.length < document.querySelectorAll('.filter-tour-status').length) fc++;
    var badge = document.getElementById('filterBadge');
    if (fc > 0) { badge.textContent = fc; badge.style.display = 'inline-flex'; }
    else { badge.style.display = 'none'; }

    updateFilteredCount();
}

// Rebuild all Insights sub-tables from filtered data and build FILTERED.insights for export
function rebuildInsightsTables() {
    // Property Performance — use FILTERED.properties
    renderPropertyPerformanceTable();
    // Lease Health — filter expiring leases by city
    renderLeaseHealthTable();
    // Price Analysis — filter price comparisons
    renderPriceAnalysisTable();
    // Stale Listings — uses FILTERED.properties internally
    renderStaleListingsTable();

    // Build combined FILTERED.insights array for export
    FILTERED.insights = [];

    // Section 1: Property Performance
    var perfRows = FILTERED.properties.slice().map(function(r) {
        var score = (parseInt(r.ViewsCount)||0) + (parseInt(r.Likes)||0)*3 + (parseInt(r.tour_count)||0)*10 + (parseInt(r.completed_tour_count)||0)*20;
        return Object.assign({}, r, { score: score });
    }).sort(function(a,b) { return b.score - a.score; });
    perfRows.forEach(function(r, i) {
        FILTERED.insights.push({ section: 'Property Performance', rank: i+1, col1: r.StreetAddress||'', col2: r.City||'', col3: r.PropertyType||'', col4: r.ListingPrice ? fmtPrice(r.ListingPrice) : '', col5: (r.ViewsCount||0)+'', col6: (r.Likes||0)+'', col7: (r.tour_count||0)+'', col8: (r.completed_tour_count||0)+'', col9: (r.days_on_market||0)+'', col10: r.Status||'', col11: r.score+'' });
    });

    // Section 2: Lease Health
    var cityFilter = document.getElementById('filterCity').value;
    var leaseRows = DATA.expiringLeases.filter(function(r) { if (cityFilter && r.City !== cityFilter) return false; return true; });
    leaseRows.forEach(function(r, i) {
        var days = parseInt(r.days_remaining)||0;
        var urgency = days < 0 ? 'EXPIRED' : days <= 30 ? 'CRITICAL' : days <= 60 ? 'WARNING' : days <= 90 ? 'WATCH' : 'OK';
        var totalP = parseInt(r.total_pmts)||0, confirmedP = parseInt(r.confirmed_pmts)||0;
        var collPct = totalP > 0 ? Math.round((confirmedP / totalP) * 100) : 0;
        FILTERED.insights.push({ section: 'Lease Health', rank: i+1, col1: r.StreetAddress||'', col2: r.City||'', col3: r.tenant_name||'', col4: fmtPrice(r.monthly_rent), col5: r.lease_end_date||'', col6: days+'', col7: urgency, col8: confirmedP+'', col9: (parseInt(r.pending_pmts)||0)+'', col10: collPct+'%', col11: r.lease_status||'' });
    });

    // Section 3: Price Negotiation
    var priceRows = DATA.priceComparisons.filter(function(r) { if (cityFilter && r.City !== cityFilter) return false; return true; });
    priceRows.forEach(function(r, i) {
        var lp = parseFloat(r.ListingPrice)||0, sp = parseFloat(r.final_sale_price)||0;
        var diffPct = r.price_diff_pct != null ? parseFloat(r.price_diff_pct) : 0;
        FILTERED.insights.push({ section: 'Price Negotiation', rank: i+1, col1: r.StreetAddress||'', col2: r.City||'', col3: r.PropertyType||'', col4: fmtPrice(lp), col5: fmtPrice(sp), col6: fmtPrice(Math.abs(sp-lp)), col7: (diffPct>=0?'+':'')+diffPct.toFixed(1)+'%', col8: r.sale_date||'', col9: '', col10: '', col11: '' });
    });

    // Section 4: Stale Listings
    var staleRows = FILTERED.properties.filter(function(r) {
        if (!r.approval_status || r.approval_status !== 'approved') return false;
        if (['Sold','Rented','Pending Sold','Pending Rented'].indexOf(r.Status) >= 0) return false;
        return (r.days_on_market || 0) >= 30 && (parseInt(r.tour_count)||0) <= 1;
    }).sort(function(a,b) { return (b.days_on_market||0) - (a.days_on_market||0); });
    staleRows.forEach(function(r, i) {
        FILTERED.insights.push({ section: 'Stale Listings', rank: i+1, col1: r.StreetAddress||'', col2: r.City||'', col3: r.PropertyType||'', col4: fmtPrice(r.ListingPrice), col5: (r.ViewsCount||0)+'', col6: (r.Likes||0)+'', col7: (r.tour_count||0)+'', col8: (r.days_on_market||0)+' days', col9: r.Status||'', col10: '', col11: '' });
    });
}

function updateFilteredCount() {
    var activeTab = document.querySelector('#reportTabs .nav-link.active');
    var key = 'properties';
    if (activeTab) {
        var target = activeTab.getAttribute('data-bs-target') || '';
        if (target.indexOf('sales')>=0) key='sales';
        else if (target.indexOf('rentals')>=0) key='rentals';
        else if (target.indexOf('tours')>=0) key='tours';
        else if (target.indexOf('commission')>=0) key='commission';
        else if (target.indexOf('insights')>=0) key='insights';
    }
    document.getElementById('filteredCount').textContent = FILTERED[key] ? FILTERED[key].length : 0;
}

function resetAllFilters() {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterCity').value = '';
    document.querySelectorAll('.filter-prop-type, .filter-status, .filter-approval, .filter-tour-status').forEach(function(c){ c.checked = true; });
    applyFilters();
}

// Filter sidebar events
document.getElementById('openFilterSidebar').addEventListener('click', function() { document.getElementById('filterSidebar').classList.add('active'); updateFilteredCount(); });
document.getElementById('closeFilterBtn').addEventListener('click', function() { document.getElementById('filterSidebar').classList.remove('active'); });
document.getElementById('filterOverlay').addEventListener('click', function() { document.getElementById('filterSidebar').classList.remove('active'); });
document.getElementById('applyFiltersBtn').addEventListener('click', function() { applyFilters(); document.getElementById('filterSidebar').classList.remove('active'); });
document.getElementById('clearFiltersBtn').addEventListener('click', function() { resetAllFilters(); });

// Auto-apply on filter change
document.querySelectorAll('.filter-prop-type, .filter-status, .filter-approval, .filter-tour-status').forEach(function(el) { el.addEventListener('change', function() { applyFilters(); updateFilteredCount(); }); });
document.getElementById('filterCity').addEventListener('change', function() { applyFilters(); updateFilteredCount(); });

// Update count on tab switch
document.querySelectorAll('#reportTabs .nav-link').forEach(function(tab) { tab.addEventListener('shown.bs.tab', updateFilteredCount); });

// =============================================
// EXPORT SYSTEM
// =============================================
function getActiveTabKey() {
    var activeTab = document.querySelector('#reportTabs .nav-link.active');
    if (!activeTab) return 'properties';
    var target = activeTab.getAttribute('data-bs-target') || '';
    if (target.indexOf('sales')>=0) return 'sales';
    if (target.indexOf('rentals')>=0) return 'rentals';
    if (target.indexOf('tours')>=0) return 'tours';
    if (target.indexOf('commission')>=0) return 'commission';
    if (target.indexOf('insights')>=0) return 'insights';
    return 'properties';
}

function getExportData() {
    var key = getActiveTabKey(), title='', headers=[], rows=[];
    switch(key) {
        case 'properties': title='My Properties Report'; headers=['#','Address','City','Type','Beds','Baths','Listing Price','Status','Approval','Views','Likes','Tours','DOM','Listed Date'];
            rows=FILTERED.properties.map(function(r,i){return[i+1,(r.StreetAddress||'')+', '+(r.Barangay||''),r.City||'',r.PropertyType||'',r.Bedrooms||'',r.Bathrooms||'',r.ListingPrice?fmtPriceExport(r.ListingPrice):'',r.Status||'',r.approval_status||'',r.ViewsCount||0,r.Likes||0,r.tour_count||0,r.days_on_market||0,r.ListingDate||''];}); break;
        case 'sales': title='My Sales Report'; headers=['#','Property','City','Type','Buyer','Listing Price','Sale Price','Diff %','Sale Date','Commission','Comm %','Comm Status'];
            rows=FILTERED.sales.map(function(r,i){
                var lp=parseFloat(r.listing_price)||0, sp=parseFloat(r.final_sale_price)||0;
                var diff=lp>0?((sp-lp)/lp*100).toFixed(1)+'%':'\u2014';
                return[i+1,r.StreetAddress||'',r.City||'',r.PropertyType||'',r.buyer_name||'',r.listing_price?fmtPriceExport(r.listing_price):'',r.final_sale_price?fmtPriceExport(r.final_sale_price):'',diff,r.sale_date||'',r.commission_amount?fmtPriceExport(r.commission_amount):'',r.commission_percentage?r.commission_percentage+'%':'',r.commission_status||''];
            }); break;
        case 'rentals': title='My Rentals Report'; headers=['#','Property','City','Type','Tenant','Monthly Rent','Deposit','Lease Start','Lease End','Term','Collected','Commission','Payments','Status'];
            rows=FILTERED.rentals.map(function(r,i){return[i+1,r.StreetAddress||'',r.City||'',r.PropertyType||'',r.tenant_name||'',r.monthly_rent?fmtPriceExport(r.monthly_rent):'',r.security_deposit?fmtPriceExport(r.security_deposit):'',r.lease_start_date||'',r.lease_end_date||'',r.lease_term_months?r.lease_term_months+' mo':'',r.total_collected?fmtPriceExport(r.total_collected):'',r.total_commission?fmtPriceExport(r.total_commission):'',((r.confirmed_payments||0)+'/'+(parseInt(r.confirmed_payments||0)+parseInt(r.pending_payments||0))),r.lease_status||'Active'];}); break;
        case 'tours': title='My Tour Requests Report'; headers=['#','Visitor','Email','Phone','Property','City','Tour Date','Time','Type','Status','Requested'];
            rows=FILTERED.tours.map(function(r,i){return[i+1,r.user_name||'',r.user_email||'',r.user_phone||'',r.StreetAddress||'',r.City||'',r.tour_date||'',r.tour_time||'',r.tour_type||'',r.request_status||'',r.requested_at||''];}); break;
        case 'commission': title='My Commission Report'; headers=['#','Type','Property','City','Prop. Type','Client','Transaction Value','Commission','Rate','Status','Date'];
            rows=FILTERED.commission.map(function(r,i){return[i+1,r.type||'',r.StreetAddress||'',r.City||'',r.PropertyType||'',r.client_name||'',r.transaction_value?fmtPriceExport(r.transaction_value):'',r.commission_amount?fmtPriceExport(r.commission_amount):'',r.commission_percentage?r.commission_percentage+'%':'',r.status||'',r.event_date||''];}); break;
        case 'insights': title='Insights Report'; headers=[]; rows=[];
            if (!FILTERED.insights || FILTERED.insights.length === 0) rebuildInsightsTables();
            // Build separate sections with proper headers for each insight table
            var insightSections = [];
            // Section 1: Property Performance Ranking
            var _perfRows = FILTERED.properties.slice().map(function(r) {
                var score = (parseInt(r.ViewsCount)||0) + (parseInt(r.Likes)||0)*3 + (parseInt(r.tour_count)||0)*10 + (parseInt(r.completed_tour_count)||0)*20;
                return Object.assign({}, r, { score: score });
            }).sort(function(a,b) { return b.score - a.score; });
            insightSections.push({
                name: 'Property Performance Ranking',
                headers: ['#','Property','City','Type','Price','Views','Likes','Tours','Completed','DOM','Status','Score'],
                rows: _perfRows.map(function(r, i) {
                    return [i+1, r.StreetAddress||'', r.City||'', r.PropertyType||'', r.ListingPrice?fmtPriceExport(r.ListingPrice):'--', r.ViewsCount||0, r.Likes||0, r.tour_count||0, r.completed_tour_count||0, r.days_on_market||0, r.Status||'', r.score];
                })
            });
            // Section 2: Lease Health Monitor
            var _cityF = document.getElementById('filterCity').value;
            var _leaseRows = DATA.expiringLeases.filter(function(r) { if (_cityF && r.City !== _cityF) return false; return true; });
            insightSections.push({
                name: 'Lease Health Monitor',
                headers: ['#','Property','City','Tenant','Rent','Lease End','Days Left','Urgency','Confirmed','Pending','Collection %','Status'],
                rows: _leaseRows.map(function(r, i) {
                    var days = parseInt(r.days_remaining)||0;
                    var urgency = days < 0 ? 'EXPIRED' : days <= 30 ? 'CRITICAL' : days <= 60 ? 'WARNING' : days <= 90 ? 'WATCH' : 'OK';
                    var totalP = parseInt(r.total_pmts)||0, confirmedP = parseInt(r.confirmed_pmts)||0;
                    var collPct = totalP > 0 ? Math.round((confirmedP / totalP) * 100) : 0;
                    return [i+1, r.StreetAddress||'', r.City||'', r.tenant_name||'', fmtPriceExport(r.monthly_rent), r.lease_end_date||'', days, urgency, confirmedP, parseInt(r.pending_pmts)||0, collPct+'%', r.lease_status||''];
                })
            });
            // Section 3: Price Negotiation Analysis
            var _priceRows = DATA.priceComparisons.filter(function(r) { if (_cityF && r.City !== _cityF) return false; return true; });
            insightSections.push({
                name: 'Price Negotiation Analysis',
                headers: ['#','Property','City','Type','Listing Price','Sale Price','Difference','Diff %','Sale Date'],
                rows: _priceRows.map(function(r, i) {
                    var lp = parseFloat(r.ListingPrice)||0, sp = parseFloat(r.final_sale_price)||0;
                    var diffPct = r.price_diff_pct != null ? parseFloat(r.price_diff_pct) : 0;
                    return [i+1, r.StreetAddress||'', r.City||'', r.PropertyType||'', fmtPriceExport(lp), fmtPriceExport(sp), fmtPriceExport(Math.abs(sp-lp)), (diffPct>=0?'+':'')+diffPct.toFixed(1)+'%', r.sale_date||''];
                })
            });
            // Section 4: Stale Listings Alert
            var _staleRows = FILTERED.properties.filter(function(r) {
                if (!r.approval_status || r.approval_status !== 'approved') return false;
                if (['Sold','Rented','Pending Sold','Pending Rented'].indexOf(r.Status) >= 0) return false;
                return (r.days_on_market || 0) >= 30 && (parseInt(r.tour_count)||0) <= 1;
            }).sort(function(a,b) { return (b.days_on_market||0) - (a.days_on_market||0); });
            insightSections.push({
                name: 'Stale Listings Alert',
                headers: ['#','Property','City','Type','Price','Views','Likes','Tours','Days on Market','Status'],
                rows: _staleRows.map(function(r, i) {
                    return [i+1, r.StreetAddress||'', r.City||'', r.PropertyType||'', fmtPriceExport(r.ListingPrice), r.ViewsCount||0, r.Likes||0, r.tour_count||0, (r.days_on_market||0)+' days', r.Status||''];
                })
            });
            return { title: title, sections: insightSections, key: key, totalRows: insightSections.reduce(function(s, sec) { return s + sec.rows.length; }, 0) };
    }
    return { title: title, headers: headers, rows: rows, key: key };
}

var pendingExportType = null;
function openExportPreview(type) {
    pendingExportType = type;
    var ed = getExportData();
    document.getElementById('exportModalTitle').textContent = ed.title + ' \u2014 Preview';
    document.getElementById('exportTabName').textContent = ed.title;
    var h = '';
    if (ed.sections) {
        // Insights: render each section as a separate table
        var totalRows = ed.totalRows || 0;
        document.getElementById('exportRowCount').textContent = totalRows;
        ed.sections.forEach(function(section) {
            h += '<div style="margin-bottom:1.25rem;"><div style="font-weight:700;color:#d4af37;font-size:0.85rem;margin-bottom:0.5rem;text-transform:uppercase;letter-spacing:0.5px;">'+esc(section.name)+' <span style="color:#94a3b8;font-weight:400;font-size:0.78rem;text-transform:none;">('+section.rows.length+' records)</span></div>';
            h += '<table class="export-preview-table"><thead><tr>';
            section.headers.forEach(function(hd) { h += '<th>'+hd+'</th>'; });
            h += '</tr></thead><tbody>';
            var preview = section.rows.slice(0, 50);
            preview.forEach(function(row) { h += '<tr>'; row.forEach(function(cell) { h += '<td>'+(cell!=null&&cell!=undefined?cell:'\u2014')+'</td>'; }); h += '</tr>'; });
            if (section.rows.length > 50) h += '<tr><td colspan="'+section.headers.length+'" style="text-align:center;color:#94a3b8;padding:0.75rem;font-style:italic;">&hellip; and '+(section.rows.length-50)+' more records</td></tr>';
            h += '</tbody></table></div>';
        });
    } else {
        document.getElementById('exportRowCount').textContent = ed.rows.length;
        h = '<table class="export-preview-table"><thead><tr>';
        ed.headers.forEach(function(hd) { h += '<th>'+hd+'</th>'; }); h += '</tr></thead><tbody>';
        var preview = ed.rows.slice(0, 100);
        preview.forEach(function(row) { h += '<tr>'; row.forEach(function(cell) { h += '<td>'+(cell!=null&&cell!=undefined?cell:'\u2014')+'</td>'; }); h += '</tr>'; });
        if (ed.rows.length > 100) h += '<tr><td colspan="'+ed.headers.length+'" style="text-align:center;color:#94a3b8;padding:1rem;font-style:italic;">&hellip; and '+(ed.rows.length-100)+' more records</td></tr>';
        h += '</tbody></table>';
    }
    document.getElementById('exportPreviewBody').innerHTML = h;
    document.getElementById('confirmExportPDF').style.display = type==='pdf'?'inline-flex':'none';
    document.getElementById('confirmExportExcel').style.display = type==='excel'?'inline-flex':'none';
    document.getElementById('exportModalOverlay').classList.add('active');
}
function closeExportPreview() { document.getElementById('exportModalOverlay').classList.remove('active'); pendingExportType=null; }

document.getElementById('btnExportPDF').addEventListener('click', function(){ openExportPreview('pdf'); });
document.getElementById('btnExportExcel').addEventListener('click', function(){ openExportPreview('excel'); });
document.getElementById('closeExportModal').addEventListener('click', closeExportPreview);
document.getElementById('cancelExportBtn').addEventListener('click', closeExportPreview);
document.getElementById('exportModalOverlay').addEventListener('click', function(e){ if(e.target===document.getElementById('exportModalOverlay')) closeExportPreview(); });

// PDF Export — load logo
var pdfLogoBase64 = null;
(function() {
    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = function() { var c=document.createElement('canvas'); c.width=img.naturalWidth; c.height=img.naturalHeight; c.getContext('2d').drawImage(img,0,0); pdfLogoBase64=c.toDataURL('image/png'); };
    img.src = '../images/Logo.png';
})();

document.getElementById('confirmExportPDF').addEventListener('click', function() {
    var ed=getExportData(), jsPDFLib=window.jspdf, doc=new jsPDFLib.jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
    var pw=doc.internal.pageSize.getWidth(), ph=doc.internal.pageSize.getHeight();
    var pdfTotalRecords = ed.sections ? ed.totalRows : ed.rows.length;
    function drawHeader(pn,tp){
        doc.setFillColor(22,18,9);doc.rect(0,0,pw,24,'F');
        doc.setFillColor(212,175,55);doc.rect(0,24,pw,1.2,'F');
        var tx=10; if(pdfLogoBase64){try{doc.addImage(pdfLogoBase64,'PNG',10,3,18,18);tx=31;}catch(e){}}
        doc.setTextColor(212,175,55);doc.setFontSize(15);doc.setFont('helvetica','bold');doc.text('HomeEstate Realty',tx,11);
        doc.setTextColor(200,200,200);doc.setFontSize(8.5);doc.setFont('helvetica','normal');
        doc.text(ed.title+'  |  Generated: '+new Date().toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'}),tx,17);
        doc.setFontSize(8);doc.setTextColor(148,163,184);doc.text('Total Records: '+pdfTotalRecords,pw-14,11,{align:'right'});
        doc.setFontSize(7);doc.text('Page '+pn+' of '+tp,pw-14,17,{align:'right'});
    }
    function drawFooter(){
        doc.setDrawColor(226,232,240);doc.setLineWidth(0.3);doc.line(10,ph-12,pw-10,ph-12);
        doc.setFontSize(7);doc.setTextColor(148,163,184);doc.text('HomeEstate Realty  \u2022  Agent Reports  \u2022  Confidential',pw/2,ph-7,{align:'center'});
    }
    var autoTableOpts = {
        theme:'grid',
        styles:{fontSize:7,cellPadding:2.5,overflow:'linebreak',lineColor:[226,232,240],lineWidth:0.2,textColor:[51,65,85]},
        headStyles:{fillColor:[15,23,42],textColor:[212,175,55],fontStyle:'bold',fontSize:6.5,cellPadding:3,lineColor:[212,175,55],lineWidth:0.4},
        alternateRowStyles:{fillColor:[248,250,252]},
        margin:{top:30,left:10,right:10,bottom:16},
        didDrawPage:function(data){drawHeader(data.pageNumber,doc.internal.getNumberOfPages());drawFooter();}
    };
    if (ed.sections) {
        // Insights: render each section as a separate table with its own section header
        var startY = 30;
        ed.sections.forEach(function(section, idx) {
            if (section.rows.length === 0) return;
            // Check if we need a new page (if less than 30mm left)
            if (startY > ph - 40) { doc.addPage(); startY = 30; }
            // Section title bar
            doc.setFillColor(15,23,42); doc.rect(10, startY, pw-20, 7, 'F');
            doc.setFontSize(8); doc.setFont('helvetica','bold'); doc.setTextColor(212,175,55);
            doc.text(section.name + '  (' + section.rows.length + ' records)', 14, startY + 4.8);
            startY += 9;
            doc.autoTable(Object.assign({}, autoTableOpts, {
                head: [section.headers],
                body: section.rows,
                startY: startY,
                didDrawPage: function(data) { drawHeader(data.pageNumber, doc.internal.getNumberOfPages()); drawFooter(); }
            }));
            startY = doc.lastAutoTable.finalY + 10;
        });
    } else {
        doc.autoTable(Object.assign({}, autoTableOpts, {
            head:[ed.headers], body:ed.rows, startY:30
        }));
    }
    var tp=doc.internal.getNumberOfPages();for(var i=1;i<=tp;i++){doc.setPage(i);drawHeader(i,tp);drawFooter();}
    doc.save(ed.title.toLowerCase().replace(/\s+/g,'_')+'_'+new Date().toISOString().slice(0,10)+'.pdf');
    closeExportPreview(); showToast('success','PDF Exported','Report downloaded as PDF.',4500);
});

document.getElementById('confirmExportExcel').addEventListener('click', function() {
    var ed=getExportData(), wb=XLSX.utils.book_new();
    if (ed.sections) {
        // Insights: create a separate sheet for each section
        ed.sections.forEach(function(section) {
            var wsData = [section.headers].concat(section.rows);
            var ws = XLSX.utils.aoa_to_sheet(wsData);
            var colW = section.headers.map(function(h,i){ var m=h.length; section.rows.forEach(function(r){ var s=String(r[i]!=null?r[i]:''); if(s.length>m) m=s.length; }); return {wch:Math.min(Math.max(m+2,10),40)}; });
            ws['!cols'] = colW;
            // Sheet name max 31 chars, remove icon chars
            var sheetName = section.name.replace(/[\u2605\u2764\u21C4\u26A0]\s*/g,'').substring(0,31);
            XLSX.utils.book_append_sheet(wb, ws, sheetName);
        });
    } else {
        var wsData=[ed.headers].concat(ed.rows), ws=XLSX.utils.aoa_to_sheet(wsData);
        var colW=ed.headers.map(function(h,i){var m=h.length;ed.rows.forEach(function(r){var s=String(r[i]!=null?r[i]:'');if(s.length>m)m=s.length;});return{wch:Math.min(Math.max(m+2,10),40)};});
        ws['!cols']=colW;
        XLSX.utils.book_append_sheet(wb,ws,ed.title.substring(0,31));
    }
    XLSX.writeFile(wb,ed.title.toLowerCase().replace(/\s+/g,'_')+'_'+new Date().toISOString().slice(0,10)+'.xlsx');
    closeExportPreview(); showToast('success','Excel Exported','Report downloaded as Excel.',4500);
});

// =============================================
// TOAST NOTIFICATIONS
// =============================================
function showToast(type,title,message,duration){
    duration=duration||4500;var c=document.getElementById('toastContainer');
    var icons={success:'bi-check-circle-fill',error:'bi-x-circle-fill',info:'bi-info-circle-fill'};
    var t=document.createElement('div');t.className='app-toast toast-'+type;
    t.innerHTML='<div class="app-toast-icon"><i class="bi '+(icons[type]||icons.info)+'"></i></div><div class="app-toast-body"><div class="app-toast-title">'+title+'</div><div class="app-toast-msg">'+message+'</div></div><button class="app-toast-close" onclick="dismissToast(this.closest(\'.app-toast\'))">&times;</button><div class="app-toast-progress" style="animation:toast-progress '+duration+'ms linear forwards;"></div>';
    c.appendChild(t);var timer=setTimeout(function(){dismissToast(t);},duration);t._timer=timer;
}
window.showToast=showToast;
function dismissToast(t){if(!t||t._dismissed)return;t._dismissed=true;clearTimeout(t._timer);t.classList.add('toast-out');setTimeout(function(){t.remove();},320);}
window.dismissToast=dismissToast;

// =============================================
// INITIALIZATION
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    applyFilters();
    renderCharts();
    renderPerformanceTrendChart();
});

document.addEventListener('skeleton:hydrated', function() {
    showToast('info', 'Reports Ready', 'Your analytics data has been loaded.', 4000);
});

})();
</script>

    <!-- SKELETON HYDRATION -->
    <script>
    (function(){
        'use strict';
        var MIN_SKELETON_MS=400,skeletonStart=Date.now(),hydrated=false;
        function hydrate(){
            if(hydrated)return;hydrated=true;
            var sk=document.getElementById('sk-screen'),pc=document.getElementById('page-content');
            if(!pc)return;
            if(!sk){pc.style.cssText='display:block;opacity:1;';document.dispatchEvent(new Event('skeleton:hydrated'));return;}
            pc.style.display='block';pc.style.opacity='0';
            requestAnimationFrame(function(){
                sk.style.transition='opacity 0.35s ease';sk.style.opacity='0';
                pc.style.transition='opacity 0.42s ease 0.1s';
                requestAnimationFrame(function(){pc.style.opacity='1';});
            });
            setTimeout(function(){
                if(sk&&sk.parentNode)sk.parentNode.removeChild(sk);
                pc.style.transition='';pc.style.opacity='';
                document.dispatchEvent(new Event('skeleton:hydrated'));
            },520);
        }
        function scheduleHydration(){var r=Math.max(0,MIN_SKELETON_MS-(Date.now()-skeletonStart));if(r>0)setTimeout(hydrate,r);else hydrate();}
        if(document.readyState==='complete')scheduleHydration();else window.addEventListener('load',scheduleHydration);
    }());
    </script>
</body>
</html>
