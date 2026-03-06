<?php
/**
 * search_results.php — Property Search & Listing Page
 *
 * Architecture: AJAX-first CSR with server-rendered filter drawer.
 *   - Initial page renders skeleton + filter drawer (lightweight queries only)
 *   - Results grid is always loaded via AJAX (?partial=grid)
 *   - Pagination, filters, search all use AJAX without full page reload
 *
 * Performance features:
 *   - Prepared statements (SQL injection prevention + query plan caching)
 *   - Separate COUNT / SELECT queries (no regex hack)
 *   - EXISTS subquery for posted_by filter (avoids unnecessary JOIN)
 *   - AbortController cancels stale requests on rapid filter changes
 *   - Smooth opacity transitions during content swaps (no jarring flicker)
 *   - Pixel-perfect skeleton screens (zero layout shift)
 *   - loading="lazy" + decoding="async" on images
 *   - content-visibility: auto for off-screen card rendering
 *   - Event delegation for dynamically-loaded pagination & card links
 *
 * Recommended database indexes:
 *   ALTER TABLE property ADD INDEX idx_search
 *       (approval_status, Status, City, PropertyType, ListingPrice, Bedrooms, Bathrooms, ListingDate);
 *   ALTER TABLE property_images ADD INDEX idx_img_sort (property_ID, SortOrder);
 *   ALTER TABLE property_log ADD INDEX idx_log_created (property_id, action, account_id);
 */
include '../connection.php';
require_once __DIR__ . '/../config/paths.php';

// ═══════════════════════════════════════════════════════════
// ─── Parameter Parsing & Validation ───────────────────────
// ═══════════════════════════════════════════════════════════
$city          = isset($_GET['city']) ? trim($_GET['city']) : '';
$property_type = isset($_GET['property_type']) ? trim($_GET['property_type']) : '';
$status        = isset($_GET['status']) ? trim($_GET['status']) : '';
$min_price     = isset($_GET['min_price']) ? max(0, (int)$_GET['min_price']) : 0;
$max_price     = isset($_GET['max_price']) ? max(0, (int)$_GET['max_price']) : 999999999;
$bedrooms      = isset($_GET['bedrooms']) ? max(0, (int)$_GET['bedrooms']) : 0;
$bathrooms     = isset($_GET['bathrooms']) ? max(0, (int)$_GET['bathrooms']) : 0;
$partial       = isset($_GET['partial']) ? $_GET['partial'] : '';
$category      = isset($_GET['category']) ? $_GET['category'] : 'all';
$search_query  = isset($_GET['q']) ? trim($_GET['q']) : '';
$posted_by     = isset($_GET['posted_by']) ? max(0, (int)$_GET['posted_by']) : 0;

// Whitelist category values
$valid_categories = ['all', 'most_viewed', 'most_liked', 'most_beds', 'for_sale', 'for_rent'];
if (!in_array($category, $valid_categories, true)) $category = 'all';

// Pagination
$per_page = 24;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

// Count active filters (pure GET param logic — no DB needed)
$active_filter_count = 0;
if ($category !== 'all') $active_filter_count++;
if ($city)               $active_filter_count++;
if ($property_type)      $active_filter_count++;
if ($status)             $active_filter_count++;
if ($min_price > 0)      $active_filter_count++;
if ($max_price < 999999999) $active_filter_count++;
if ($bedrooms > 0)       $active_filter_count++;
if ($bathrooms > 0)      $active_filter_count++;
if ($posted_by > 0)      $active_filter_count++;


// ═══════════════════════════════════════════════════════════
// ─── AJAX PARTIAL RENDER (?partial=grid) ──────────────────
// ═══════════════════════════════════════════════════════════
if ($partial === 'grid') {

    // ── Build WHERE clause with prepared-statement parameters ──
    $where  = "p.approval_status = 'approved' AND p.Status NOT IN ('Pending Sold','Pending Rented')";
    $params = [];
    $types  = '';

    if ($city) {
        $where .= " AND p.City = ?";
        $params[] = $city;
        $types .= 's';
    }
    if ($property_type) {
        $where .= " AND p.PropertyType = ?";
        $params[] = $property_type;
        $types .= 's';
    }
    if ($status) {
        $where .= " AND p.Status = ?";
        $params[] = $status;
        $types .= 's';
    }
    if ($min_price > 0) {
        $where .= " AND p.ListingPrice >= ?";
        $params[] = $min_price;
        $types .= 'i';
    }
    if ($max_price < 999999999) {
        $where .= " AND p.ListingPrice <= ?";
        $params[] = $max_price;
        $types .= 'i';
    }
    if ($bedrooms > 0) {
        $where .= " AND p.Bedrooms >= ?";
        $params[] = $bedrooms;
        $types .= 'i';
    }
    if ($bathrooms > 0) {
        $where .= " AND p.Bathrooms >= ?";
        $params[] = $bathrooms;
        $types .= 'i';
    }
    if ($posted_by > 0) {
        // Efficient EXISTS subquery — avoids JOIN on every query
        $where .= " AND EXISTS (SELECT 1 FROM property_log pl WHERE pl.property_id = p.property_ID AND pl.action = 'CREATED' AND pl.account_id = ?)";
        $params[] = $posted_by;
        $types .= 'i';
    }
    if ($search_query) {
        $like = '%' . $search_query . '%';
        $where .= " AND (p.StreetAddress LIKE ? OR p.City LIKE ? OR p.Province LIKE ? OR p.PropertyType LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'ssss';
    }

    // Category-specific WHERE additions (hardcoded values — not user input)
    if ($category === 'for_sale' && !$status) {
        $where .= " AND p.Status = 'For Sale'";
    } elseif ($category === 'for_rent' && !$status) {
        $where .= " AND p.Status = 'For Rent'";
    }

    // ── ORDER BY based on category ──
    switch ($category) {
        case 'most_viewed': $order = "p.ViewsCount DESC"; break;
        case 'most_liked':  $order = "p.Likes DESC"; break;
        case 'most_beds':   $order = "p.Bedrooms DESC, p.Bathrooms DESC"; break;
        default:            $order = "p.ListingDate DESC"; break;
    }

    // ── COUNT query (clean — no regex, no subquery wrapper) ──
    $count_sql  = "SELECT COUNT(*) AS total FROM property p WHERE $where";
    $count_stmt = $conn->prepare($count_sql);
    if ($types && $params) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total       = (int)$count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = max(1, (int)ceil($total / $per_page));
    $count_stmt->close();

    // Clamp page to valid range
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    // ── SELECT query with LIMIT / OFFSET ──
    $select_sql = "
        SELECT
            p.property_ID, p.StreetAddress, p.City, p.Province, p.PropertyType,
            p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice, p.Status,
            p.Likes, p.ViewsCount,
            (SELECT pi.PhotoURL
             FROM property_images pi
             WHERE pi.property_ID = p.property_ID
             ORDER BY pi.SortOrder ASC
             LIMIT 1) AS PhotoURL
        FROM property p
        WHERE $where
        ORDER BY $order
        LIMIT ? OFFSET ?
    ";
    $select_types  = $types . 'ii';
    $select_params = array_merge($params, [$per_page, $offset]);

    $stmt = $conn->prepare($select_sql);
    if ($select_types) {
        $stmt->bind_param($select_types, ...$select_params);
    }
    $stmt->execute();
    $properties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    // ── Render AJAX partial HTML ──
?>
<!-- Results Header -->
<div class="results-header">
    <div class="results-count">
        <span><?= $total ?></span> Properties Found
        <?php if ($total > $per_page): ?>
            <span class="active-category-label">Page <?= $page ?> of <?= $total_pages ?></span>
        <?php endif; ?>
        <?php if ($category !== 'all'): ?>
            <span class="active-category-label"><?= ucwords(str_replace('_', ' ', $category)) ?></span>
        <?php endif; ?>
    </div>
    <div class="results-actions">
        <button class="filter-toggle-btn" data-action="toggle-drawer">
            <i class="bi bi-sliders"></i> Filters
            <span class="filter-badge-count" id="filterBadgeCount2" style="display:<?= $active_filter_count > 0 ? 'inline-flex' : 'none' ?>"><?= $active_filter_count ?></span>
        </button>
    </div>
</div>

<!-- Pagination State (consumed by JS) -->
<input type="hidden" id="srTotalPages" value="<?= $total_pages ?>">
<input type="hidden" id="srCurrentPage" value="<?= $page ?>">
<input type="hidden" id="srTotal" value="<?= $total ?>">

<?php if (empty($properties)): ?>
    <div class="no-results">
        <i class="bi bi-house-x-fill"></i>
        <h3>No Properties Found</h3>
        <p>Try adjusting your filters or search to see more results</p>
    </div>
<?php else: ?>
    <div class="properties-grid">
        <?php foreach ($properties as $prop): ?>
        <div class="property-card">
            <a href="property_details.php?id=<?= $prop['property_ID'] ?>" class="property-card-link">
                <div class="property-image-container">
                    <img src="../<?= htmlspecialchars($prop['PhotoURL'] ?? 'images/placeholder.jpg') ?>"
                         class="property-image"
                         alt="<?= htmlspecialchars($prop['PropertyType'] . ' in ' . $prop['City']) ?>"
                         loading="lazy"
                         decoding="async"
                         width="400"
                         height="220">
                    <div class="property-badge<?php
                        $st = trim($prop['Status']);
                        if ($st === 'For Rent') echo ' for-rent';
                        elseif ($st === 'Rented') echo ' rented';
                        elseif ($st === 'Sold') echo ' sold';
                    ?>">
                        <?= htmlspecialchars($prop['Status']) ?>
                    </div>
                    <div class="property-stats">
                        <div class="stat-badge views">
                            <i class="bi bi-eye-fill"></i>
                            <span><?= number_format($prop['ViewsCount'] ?? 0) ?></span>
                        </div>
                        <div class="stat-badge likes">
                            <i class="bi bi-heart-fill"></i>
                            <span><?= number_format($prop['Likes'] ?? 0) ?></span>
                        </div>
                    </div>
                    <div class="property-image-overlay"></div>
                </div>
                <div class="property-body">
                    <div class="property-price">₱<?= number_format($prop['ListingPrice']) ?></div>
                    <div class="property-address">
                        <i class="bi bi-geo-alt-fill"></i>
                        <?= htmlspecialchars($prop['StreetAddress']) ?>, <?= htmlspecialchars($prop['City']) ?>
                    </div>
                    <div class="property-features">
                        <div class="feature-item"><i class="bi bi-door-open-fill"></i> <?= $prop['Bedrooms'] ?> Beds</div>
                        <div class="feature-item"><i class="bi bi-droplet-fill"></i> <?= $prop['Bathrooms'] ?> Baths</div>
                        <div class="feature-item"><i class="bi bi-arrows-fullscreen"></i> <?= number_format($prop['SquareFootage']) ?> ft²</div>
                    </div>
                    <div class="property-card-footer">
                        <div class="property-type"><?= htmlspecialchars($prop['PropertyType']) ?></div>
                        <span class="view-details-link">View Details <i class="bi bi-arrow-right"></i></span>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="sr-pagination">
        <button class="sr-page-btn" data-page="<?= $page - 1 ?>" <?= $page <= 1 ? 'disabled' : '' ?>>
            <i class="bi bi-chevron-left"></i>
        </button>
        <?php
        $start = max(1, $page - 2);
        $end   = min($total_pages, $page + 2);
        if ($start > 1) {
            echo '<button class="sr-page-btn" data-page="1">1</button>';
            if ($start > 2) echo '<span class="sr-page-dots">...</span>';
        }
        for ($i = $start; $i <= $end; $i++):
        ?>
            <button class="sr-page-btn<?= $i === $page ? ' sr-page-active' : '' ?>" data-page="<?= $i ?>"><?= $i ?></button>
        <?php endfor;
        if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<span class="sr-page-dots">...</span>';
            echo '<button class="sr-page-btn" data-page="' . $total_pages . '">' . $total_pages . '</button>';
        }
        ?>
        <button class="sr-page-btn" data-page="<?= $page + 1 ?>" <?= $page >= $total_pages ? 'disabled' : '' ?>>
            <i class="bi bi-chevron-right"></i>
        </button>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php
    exit;
}


// ═══════════════════════════════════════════════════════════
// ─── FULL PAGE RENDER ─────────────────────────────────────
// Only lightweight queries needed for the filter drawer
// ═══════════════════════════════════════════════════════════
$cities_result = $conn->query("SELECT DISTINCT City FROM property WHERE approval_status = 'approved' ORDER BY City ASC");
$cities = $cities_result->fetch_all(MYSQLI_ASSOC);

$types_result = $conn->query("SELECT type_name AS PropertyType FROM property_types ORDER BY type_name ASC");
$types = $types_result->fetch_all(MYSQLI_ASSOC);

$agents_result = $conn->query("
    SELECT DISTINCT a.account_id, a.first_name, a.last_name, ur.role_name
    FROM accounts a
    JOIN property_log pl ON a.account_id = pl.account_id AND pl.action = 'CREATED'
    JOIN property p ON pl.property_id = p.property_ID AND p.approval_status = 'approved'
    LEFT JOIN user_roles ur ON a.role_id = ur.role_id
    ORDER BY a.first_name ASC, a.last_name ASC
");
$agents = $agents_result ? $agents_result->fetch_all(MYSQLI_ASSOC) : [];

$price_range_result = $conn->query("SELECT MIN(ListingPrice) AS minp, MAX(ListingPrice) AS maxp FROM property WHERE approval_status = 'approved' AND Status NOT IN ('Pending Sold','Pending Rented')");
$price_range = $price_range_result ? $price_range_result->fetch_assoc() : ['minp' => 0, 'maxp' => 100000000];
$min_bound = (int)($price_range['minp'] ?? 0);
$max_bound = (int)($price_range['maxp'] ?? 100000000);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Properties - HomeEstate Realty</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">

    <style>
        /* ═══════════════════════════════════════════════════
           Design Tokens
           ═══════════════════════════════════════════════════ */
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
            --gray-400: #a0aab5;
            --gray-500: #7a8a99;
            --gray-600: #5a6c7d;
            --gray-300: #c5cdd5;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { overflow-y: scroll; /* Prevent scrollbar shift between pages */ }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            line-height: 1.6;
            color: var(--white);
            overflow-x: hidden;
        }

        .page-content {
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px 24px 60px;
        }

        /* ═══════════════════════════════════════════════════
           Results Area — Loading Transitions
           ═══════════════════════════════════════════════════ */
        #resultsArea {
            transition: opacity 0.25s ease-out;
            min-height: 500px;
        }

        /* ═══════════════════════════════════════════════════
           Category Buttons (in drawer)
           ═══════════════════════════════════════════════════ */
        .category-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .category-btn {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            padding: 10px 12px;
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px; color: var(--gray-400); font-size: 0.8125rem;
            font-weight: 600; cursor: pointer; transition: all 0.15s;
            white-space: nowrap;
        }
        .category-btn:hover {
            background: rgba(255,255,255,0.08); color: var(--white);
            border-color: rgba(255,255,255,0.15);
            transform: translateY(-1px);
        }
        .category-btn.active {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: var(--black); border-color: var(--gold);
            box-shadow: 0 2px 8px rgba(212,175,55,0.3);
        }
        .category-btn.active i { color: var(--black); }
        .category-btn i { font-size: 0.85rem; }

        /* ═══════════════════════════════════════════════════
           Results Header
           ═══════════════════════════════════════════════════ */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px; padding-bottom: 16px;
            border-bottom: 1px solid rgba(37,99,235,0.12);
        }
        .results-count {
            font-size: 0.875rem; color: var(--gray-400); font-weight: 500;
            white-space: nowrap;
        }
        .results-count span { color: var(--white); font-weight: 700; font-size: 1.125rem; }
        .active-category-label {
            display: block;
            color: var(--gray-500); font-weight: 500; font-size: 0.75rem;
            margin-top: 2px;
        }
        .results-actions {
            display: flex; align-items: center; gap: 8px;
        }

        /* ═══════════════════════════════════════════════════
           Filter Toggle Button
           ═══════════════════════════════════════════════════ */
        .filter-toggle-btn {
            display: flex; align-items: center; gap: 8px; height: 48px; padding: 0 18px;
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px; color: var(--white); font-size: 0.9375rem; font-weight: 600;
            cursor: pointer; transition: all 0.15s; position: relative;
            white-space: nowrap;
        }
        .filter-toggle-btn:hover { background: rgba(37,99,235,0.12); border-color: rgba(37,99,235,0.3); }
        .filter-toggle-btn i { font-size: 1rem; }
        .filter-badge-count {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px; padding: 0 7px;
            background: var(--gold); color: var(--black); font-size: 0.75rem;
            font-weight: 700; border-radius: 11px;
        }

        /* ═══════════════════════════════════════════════════
           Filter Drawer (Right Side)
           ═══════════════════════════════════════════════════ */
        .drawer-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6); backdrop-filter: blur(4px);
            z-index: 1040;
            opacity: 0; visibility: hidden; transition: opacity 0.25s, visibility 0.25s;
        }
        .drawer-overlay.open { opacity: 1; visibility: visible; }
        .filter-drawer {
            position: fixed; top: 0; right: 0; width: 520px; max-width: 92vw;
            height: 100vh; height: 100dvh;
            background: linear-gradient(180deg, #131316 0%, #0c0c0e 100%);
            border-left: 1px solid rgba(37,99,235,0.15);
            z-index: 1050;
            display: flex; flex-direction: column;
            transform: translateX(100%);
            transition: transform 0.32s cubic-bezier(0.22,1,0.36,1);
            box-shadow: -8px 0 40px rgba(0,0,0,0.5);
            overflow: hidden;
        }
        .filter-drawer.open { transform: translateX(0); }
        .drawer-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 22px 28px; border-bottom: 1px solid rgba(255,255,255,0.07); flex-shrink: 0;
            background: rgba(255,255,255,0.015);
        }
        .drawer-header h3 {
            font-size: 1.2rem; font-weight: 700; color: var(--white);
            display: flex; align-items: center; gap: 10px;
        }
        .drawer-header h3 i { color: var(--gold); font-size: 1.1rem; }
        .drawer-header .drawer-subtitle {
            font-size: 0.8rem; color: var(--gray-500); font-weight: 400; margin-top: 2px;
        }
        .drawer-close {
            width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px; color: var(--gray-400); font-size: 1.15rem; cursor: pointer;
            transition: all 0.15s;
        }
        .drawer-close:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #ef4444; }
        .drawer-body {
            flex: 1; overflow-y: auto; padding: 24px 28px;
            overscroll-behavior: contain;
        }
        .drawer-body::-webkit-scrollbar { width: 5px; }
        .drawer-body::-webkit-scrollbar-track { background: transparent; }
        .drawer-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 3px; }
        .drawer-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
        .drawer-footer {
            padding: 18px 28px; border-top: 1px solid rgba(255,255,255,0.07);
            display: flex; gap: 10px; flex-shrink: 0;
            background: rgba(255,255,255,0.015);
        }
        .drawer-footer .btn-apply {
            flex: 1; padding: 13px;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            border: none; border-radius: 10px; color: var(--black); font-weight: 700;
            font-size: 0.9rem; cursor: pointer; transition: all 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .drawer-footer .btn-apply:hover { box-shadow: 0 4px 20px rgba(212,175,55,0.35); transform: translateY(-1px); }
        .drawer-footer .btn-clear {
            padding: 13px 22px; background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 10px;
            color: var(--gray-300); font-weight: 600; font-size: 0.85rem;
            cursor: pointer; transition: all 0.15s;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .drawer-footer .btn-clear:hover { background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.25); color: #ef4444; }

        /* ═══════════════════════════════════════════════════
           Filter Controls
           ═══════════════════════════════════════════════════ */
        .filter-section-divider {
            height: 1px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06), transparent);
            margin: 20px 0;
        }
        .filter-columns {
            display: grid; grid-template-columns: 1fr 1fr; gap: 18px;
        }
        .filter-group { margin-bottom: 22px; }
        .filter-label {
            font-size: 0.78rem; font-weight: 700; color: var(--gray-300);
            margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
            text-transform: uppercase; letter-spacing: 0.6px;
        }
        .filter-label i { color: var(--gold); font-size: 0.85rem; }
        .filter-select {
            width: 100%; padding: 11px 40px 11px 14px; background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.09); border-radius: 10px;
            color: var(--white); font-size: 0.875rem; transition: border-color 0.2s, background 0.2s; cursor: pointer;
            -webkit-appearance: none; -moz-appearance: none; appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'><path fill='%23a0aab5' d='M0 0l5 6 5-6z'/></svg>");
            background-repeat: no-repeat; background-position: right 14px center; background-size: 10px 6px;
        }
        input.filter-select {
            background-image: none; padding-right: 14px;
        }
        .filter-select:hover { border-color: rgba(255,255,255,0.18); background: rgba(255,255,255,0.06); }
        .filter-select:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .filter-select option { background-color: var(--black-light); color: var(--white); }
        .filter-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-btn {
            padding: 9px 18px; background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.09); border-radius: 10px;
            color: var(--gray-400); font-size: 0.8125rem; cursor: pointer;
            font-weight: 600; transition: all 0.18s;
        }
        .filter-btn:hover { background: rgba(255,255,255,0.1); color: var(--white); border-color: rgba(255,255,255,0.2); transform: translateY(-1px); }
        .filter-btn.active { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: var(--black); border-color: var(--gold); box-shadow: 0 2px 8px rgba(212,175,55,0.25); }

        /* Price Slider */
        .price-slider-container { position: relative; height: 40px; margin-bottom: 1rem; margin-top: 0.5rem; }
        .price-slider-track {
            position: absolute; top: 50%; left: 0; right: 0; height: 5px;
            background: rgba(255,255,255,0.08); border-radius: 3px; transform: translateY(-50%);
        }
        .price-slider-range { position: absolute; height: 100%; background: linear-gradient(90deg, var(--gold-dark), var(--gold)); border-radius: 3px; }
        .price-range-slider {
            position: absolute; width: 100%; height: 5px; top: 50%;
            transform: translateY(-50%); background: transparent;
            pointer-events: none; -webkit-appearance: none; appearance: none; margin: 0;
        }
        .price-range-slider::-webkit-slider-thumb {
            -webkit-appearance: none; width: 18px; height: 18px; border-radius: 50%;
            background: var(--gold); border: 2px solid var(--gold-dark); cursor: pointer;
            pointer-events: all; box-shadow: 0 2px 6px rgba(212,175,55,0.4);
        }
        .price-range-slider::-moz-range-thumb {
            width: 18px; height: 18px; border-radius: 50%;
            background: var(--gold); border: 2px solid var(--gold-dark); cursor: pointer;
            pointer-events: all; box-shadow: 0 2px 6px rgba(212,175,55,0.4);
        }
        .price-range-inputs { display: grid; grid-template-columns: 1fr auto 1fr; gap: 10px; align-items: center; }
        .price-input { position: relative; }
        .price-input input {
            width: 100%; padding: 9px 10px 9px 24px; background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 6px;
            color: var(--white); font-size: 0.8125rem; font-weight: 600;
        }
        .price-input input:focus { outline: none; border-color: var(--blue); }
        .price-input .currency-symbol {
            position: absolute; left: 9px; top: 50%; transform: translateY(-50%);
            color: var(--gold); font-weight: 700; font-size: 0.75rem;
        }
        .range-divider { color: var(--gray-500); font-weight: 600; font-size: 0.8rem; }

        /* Active filter chips */
        .active-filters-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .active-filters-bar:empty { margin-bottom: 0; }
        .filter-chip {
            display: flex; align-items: center; gap: 6px; padding: 6px 14px;
            background: rgba(37,99,235,0.1); border: 1px solid rgba(37,99,235,0.2);
            border-radius: 20px; color: var(--blue-light); font-size: 0.8rem; font-weight: 500;
        }
        .filter-chip .chip-remove { cursor: pointer; font-size: 0.9rem; opacity: 0.7; transition: opacity 0.15s; }
        .filter-chip .chip-remove:hover { opacity: 1; color: #ef4444; }

        /* ═══════════════════════════════════════════════════
           Property Cards
           ═══════════════════════════════════════════════════ */
        .properties-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 18px;
        }
        .property-card {
            background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px; overflow: hidden;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s; position: relative;
            content-visibility: auto;
            contain-intrinsic-size: auto 420px;
        }
        .property-card:hover {
            border-color: rgba(37,99,235,0.3); box-shadow: 0 12px 32px rgba(0,0,0,0.4);
            transform: translateY(-4px);
        }
        .property-card-link { text-decoration: none; color: inherit; display: block; }
        .property-image-container {
            position: relative; height: 220px; overflow: hidden;
            background-color: var(--black);
        }
        .property-image {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 0.3s; will-change: transform;
        }
        .property-card:hover .property-image { transform: scale(1.06); }
        .property-image-overlay {
            position: absolute; bottom: 0; left: 0; right: 0; height: 60%;
            background: linear-gradient(transparent, rgba(0,0,0,0.5)); pointer-events: none;
        }
        .property-stats { position: absolute; right: 12px; top: 12px; display: flex; gap: 6px; z-index: 2; }
        .stat-badge {
            display: flex; align-items: center; gap: 5px; padding: 5px 10px;
            background: rgba(0,0,0,0.85); border-radius: 6px;
            font-size: 0.75rem; font-weight: 600; color: var(--white);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .stat-badge.views i { color: var(--blue-light); }
        .stat-badge.likes i { color: #ef4444; }
        .property-badge {
            position: absolute; top: 12px; left: 12px; padding: 5px 12px;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: var(--black); font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; border-radius: 6px; z-index: 2;
        }
        .property-badge.for-rent { background: linear-gradient(135deg, var(--blue-dark), var(--blue)); color: var(--white); }
        .property-badge.rented { background: linear-gradient(135deg, #7c3aed, #8b5cf6); color: var(--white); }
        .property-badge.sold { background: linear-gradient(135deg, #475569, #64748b); color: var(--white); }
        .property-body { padding: 18px 20px 20px; }
        .property-price {
            font-size: 1.4rem; font-weight: 800;
            background: linear-gradient(135deg, var(--gold), var(--gold-light));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; margin-bottom: 8px;
        }
        .property-address {
            font-size: 0.875rem; color: var(--gray-400); margin-bottom: 14px;
            display: flex; align-items: center; gap: 6px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .property-features { display: flex; gap: 14px; margin-bottom: 14px; flex-wrap: wrap; }
        .feature-item {
            display: flex; align-items: center; gap: 5px;
            font-size: 0.8125rem; color: var(--gray-300);
        }
        .feature-item i { color: var(--gray-500); font-size: 0.85rem; }
        .property-card-footer {
            display: flex; align-items: center; justify-content: space-between;
            padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06);
        }
        .property-type {
            font-size: 0.75rem; color: var(--gray-500);
            text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;
        }
        .view-details-link {
            font-size: 0.8125rem; color: var(--blue-light); font-weight: 600;
            display: flex; align-items: center; gap: 4px; transition: color 0.15s;
        }
        .property-card:hover .view-details-link { color: var(--gold); }
        .no-results {
            text-align: center; padding: 80px 20px;
            background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px;
        }
        .no-results i { font-size: 4rem; color: var(--gray-600); margin-bottom: 20px; }
        .no-results h3 { font-size: 1.4rem; color: var(--white); margin-bottom: 10px; }
        .no-results p { color: var(--gray-400); }

        /* ═══════════════════════════════════════════════════
           Skeleton Shimmer Animation
           ═══════════════════════════════════════════════════ */
        @keyframes sk-shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .sk-shimmer {
            background: linear-gradient(
                90deg,
                rgba(255,255,255,0.03) 25%,
                rgba(255,255,255,0.07) 50%,
                rgba(255,255,255,0.03) 75%
            );
            background-size: 1600px 100%;
            animation: sk-shimmer 1.5s ease-in-out infinite;
        }
        .sk-card {
            pointer-events: none;
            content-visibility: visible !important; /* Override auto so skeletons are always visible */
        }
        .sk-text { display: block; border-radius: 4px; }

        /* ═══════════════════════════════════════════════════
           Pagination
           ═══════════════════════════════════════════════════ */
        .sr-pagination {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            margin-top: 32px; padding-top: 24px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .sr-page-btn {
            min-width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px; color: var(--gray-400); font-size: 0.875rem; font-weight: 600;
            cursor: pointer; transition: all 0.15s; padding: 0 12px;
        }
        .sr-page-btn:hover:not(:disabled) {
            background: rgba(37,99,235,0.12); border-color: rgba(37,99,235,0.3); color: var(--white);
        }
        .sr-page-btn:disabled { opacity: 0.3; cursor: not-allowed; }
        .sr-page-btn.sr-page-active {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            border-color: var(--gold); color: var(--black); font-weight: 700;
        }
        .sr-page-dots { color: var(--gray-500); font-size: 0.875rem; padding: 0 4px; }

        /* ═══════════════════════════════════════════════════
           Responsive
           ═══════════════════════════════════════════════════ */
        @media (max-width: 968px) {
            .results-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .results-actions { width: 100%; justify-content: flex-end; }
        }
        @media (max-width: 768px) {
            .page-content { padding: 20px 14px 60px; }
            .properties-grid { grid-template-columns: 1fr; }
            .results-header { gap: 10px; }
            .results-actions { width: 100%; justify-content: space-between; }
            .filter-drawer { width: 100%; max-width: 100vw; }
            .category-buttons { grid-template-columns: 1fr; }
            .filter-columns { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .sr-page-btn { min-width: 36px; height: 36px; font-size: 0.8rem; padding: 0 8px; }
            .sr-pagination { gap: 4px; }
        }
        @media (min-width: 769px) and (max-width: 1024px) {
            .properties-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
        }
        @media (min-width: 1400px) {
            .properties-grid { grid-template-columns: repeat(4, 1fr); }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<!-- Drawer Overlay -->
<div class="drawer-overlay" id="drawerOverlay"></div>

<!-- Filter Drawer (Right Side) -->
<div class="filter-drawer" id="filterDrawer">
    <div class="drawer-header">
        <div>
            <h3><i class="bi bi-sliders2"></i> Filter Properties</h3>
            <div class="drawer-subtitle">Refine your property search</div>
        </div>
        <button class="drawer-close" onclick="toggleDrawer()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="drawer-body" id="drawerBody">
        <form id="filterForm" onsubmit="return false;">
            <!-- Categories -->
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-grid-3x3-gap"></i> Quick Categories</label>
                <div class="category-buttons">
                    <button type="button" class="category-btn <?= $category === 'most_viewed' ? 'active' : '' ?>" data-category="most_viewed" onclick="setCategoryFilter('most_viewed')">
                        <i class="bi bi-eye-fill"></i> Most Viewed
                    </button>
                    <button type="button" class="category-btn <?= $category === 'most_liked' ? 'active' : '' ?>" data-category="most_liked" onclick="setCategoryFilter('most_liked')">
                        <i class="bi bi-heart-fill"></i> Most Liked
                    </button>
                    <button type="button" class="category-btn <?= $category === 'most_beds' ? 'active' : '' ?>" data-category="most_beds" onclick="setCategoryFilter('most_beds')">
                        <i class="bi bi-door-open-fill"></i> Most Beds
                    </button>
                    <button type="button" class="category-btn <?= $category === 'for_sale' ? 'active' : '' ?>" data-category="for_sale" onclick="setCategoryFilter('for_sale')">
                        <i class="bi bi-house-door-fill"></i> For Sale
                    </button>
                    <button type="button" class="category-btn <?= $category === 'for_rent' ? 'active' : '' ?>" data-category="for_rent" onclick="setCategoryFilter('for_rent')">
                        <i class="bi bi-key-fill"></i> For Rent
                    </button>
                </div>
            </div>

            <div class="filter-section-divider"></div>

            <!-- Search -->
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" class="filter-select" id="searchInput" placeholder="Address, city, or property type..." value="<?= htmlspecialchars($search_query) ?>" style="cursor: text;">
            </div>

            <!-- Location & Property Type -->
            <div class="filter-columns">
                <div class="filter-group">
                    <label class="filter-label"><i class="bi bi-geo-alt"></i> Location</label>
                    <select class="filter-select" id="citySelect">
                        <option value="">All Cities</option>
                        <?php foreach ($cities as $c): ?>
                            <option value="<?= htmlspecialchars($c['City']) ?>" <?= $city === $c['City'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['City']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label"><i class="bi bi-building"></i> Property Type</label>
                    <select class="filter-select" id="typeSelect">
                        <option value="">All Types</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= htmlspecialchars($t['PropertyType']) ?>" <?= $property_type === $t['PropertyType'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['PropertyType']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Posted By Agent -->
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-person-badge"></i> Posted By</label>
                <select class="filter-select" id="postedBySelect">
                    <option value="0">All Agents</option>
                    <?php foreach ($agents as $ag): ?>
                        <option value="<?= (int)$ag['account_id'] ?>" <?= $posted_by === (int)$ag['account_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ag['first_name'] . ' ' . $ag['last_name']) ?>
                            <?php if (!empty($ag['role_name'])): ?> (<?= ucfirst($ag['role_name']) ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-section-divider"></div>

            <!-- Status -->
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-tag"></i> Status</label>
                <div class="filter-buttons">
                    <button type="button" class="filter-btn <?= $status === '' ? 'active' : '' ?>" onclick="setFilter('status', '')">All</button>
                    <button type="button" class="filter-btn <?= $status === 'For Sale' ? 'active' : '' ?>" onclick="setFilter('status', 'For Sale')">For Sale</button>
                    <button type="button" class="filter-btn <?= $status === 'For Rent' ? 'active' : '' ?>" onclick="setFilter('status', 'For Rent')">For Rent</button>
                </div>
                <input type="hidden" id="statusInput" value="<?= htmlspecialchars($status) ?>">
            </div>

            <!-- Price Range -->
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-currency-exchange"></i> Price Range</label>
                <div class="price-slider-container">
                    <div class="price-slider-track">
                        <div class="price-slider-range" id="priceSliderRange"></div>
                    </div>
                    <input type="range" id="priceMinSlider" class="price-range-slider" min="<?= $min_bound ?>" max="<?= $max_bound ?>" value="<?= $min_price > 0 ? $min_price : $min_bound ?>" step="100000">
                    <input type="range" id="priceMaxSlider" class="price-range-slider" min="<?= $min_bound ?>" max="<?= $max_bound ?>" value="<?= $max_price < 999999999 ? $max_price : $max_bound ?>" step="100000">
                </div>
                <div class="price-range-inputs">
                    <div class="price-input">
                        <span class="currency-symbol">P</span>
                        <input type="text" id="priceMinDisplay" value="<?= number_format($min_price > 0 ? $min_price : $min_bound) ?>" readonly>
                    </div>
                    <span class="range-divider">-</span>
                    <div class="price-input">
                        <span class="currency-symbol">P</span>
                        <input type="text" id="priceMaxDisplay" value="<?= number_format($max_price < 999999999 ? $max_price : $max_bound) ?>" readonly>
                    </div>
                </div>
                <input type="hidden" id="minPriceInput" value="<?= $min_price > 0 ? $min_price : $min_bound ?>">
                <input type="hidden" id="maxPriceInput" value="<?= $max_price < 999999999 ? $max_price : $max_bound ?>">
            </div>

            <div class="filter-section-divider"></div>

            <!-- Bedrooms & Bathrooms -->
            <div class="filter-columns">
                <div class="filter-group">
                    <label class="filter-label"><i class="bi bi-door-open"></i> Bedrooms</label>
                    <div class="filter-buttons">
                        <button type="button" class="filter-btn <?= $bedrooms === 0 ? 'active' : '' ?>" onclick="setFilter('bedrooms', 0)">Any</button>
                        <button type="button" class="filter-btn <?= $bedrooms === 1 ? 'active' : '' ?>" onclick="setFilter('bedrooms', 1)">1+</button>
                        <button type="button" class="filter-btn <?= $bedrooms === 2 ? 'active' : '' ?>" onclick="setFilter('bedrooms', 2)">2+</button>
                        <button type="button" class="filter-btn <?= $bedrooms === 3 ? 'active' : '' ?>" onclick="setFilter('bedrooms', 3)">3+</button>
                        <button type="button" class="filter-btn <?= $bedrooms === 4 ? 'active' : '' ?>" onclick="setFilter('bedrooms', 4)">4+</button>
                    </div>
                    <input type="hidden" id="bedroomsInput" value="<?= $bedrooms ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label"><i class="bi bi-droplet"></i> Bathrooms</label>
                    <div class="filter-buttons">
                        <button type="button" class="filter-btn <?= $bathrooms === 0 ? 'active' : '' ?>" onclick="setFilter('bathrooms', 0)">Any</button>
                        <button type="button" class="filter-btn <?= $bathrooms === 1 ? 'active' : '' ?>" onclick="setFilter('bathrooms', 1)">1+</button>
                        <button type="button" class="filter-btn <?= $bathrooms === 2 ? 'active' : '' ?>" onclick="setFilter('bathrooms', 2)">2+</button>
                        <button type="button" class="filter-btn <?= $bathrooms === 3 ? 'active' : '' ?>" onclick="setFilter('bathrooms', 3)">3+</button>
                    </div>
                    <input type="hidden" id="bathroomsInput" value="<?= $bathrooms ?>">
                </div>
            </div>

            <input type="hidden" id="categoryInput" value="<?= htmlspecialchars($category) ?>">
            <input type="hidden" id="postedByInput" value="<?= $posted_by ?>">
        </form>
    </div>
    <div class="drawer-footer">
        <button class="btn-clear" onclick="clearFilters()"><i class="bi bi-arrow-counterclockwise"></i> Reset All</button>
        <button class="btn-apply" onclick="applyAndClose()"><i class="bi bi-check2-circle"></i> Apply Filters</button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     Page Content
     ═══════════════════════════════════════════════════════════ -->
<div class="page-content">
    <div class="active-filters-bar" id="activeFiltersBar"></div>

    <div id="resultsArea">
        <!-- ─── Initial Skeleton — pixel-matched to real card layout ───
             Uses identical class names (.property-card, .property-image-container,
             .property-body) so CSS dimensions are identical. Zero layout shift. -->
        <div class="results-header">
            <div class="results-count">
                <span class="sk-text sk-shimmer" style="width:160px;height:20px;display:inline-block"></span>
            </div>
            <div class="results-actions">
                <span class="sk-text sk-shimmer" style="width:120px;height:48px;display:inline-block;border-radius:8px"></span>
            </div>
        </div>
        <div class="properties-grid">
            <?php for ($sk = 0; $sk < 6; $sk++): ?>
            <div class="property-card sk-card">
                <div class="property-image-container sk-shimmer"></div>
                <div class="property-body">
                    <div class="sk-text sk-shimmer" style="width:50%;height:28px;margin-bottom:8px"></div>
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:14px">
                        <div class="sk-text sk-shimmer" style="width:12px;height:12px;border-radius:50%;flex-shrink:0"></div>
                        <div class="sk-text sk-shimmer" style="width:75%;height:14px"></div>
                    </div>
                    <div style="display:flex;gap:14px;margin-bottom:14px">
                        <div class="sk-text sk-shimmer" style="width:65px;height:14px"></div>
                        <div class="sk-text sk-shimmer" style="width:65px;height:14px"></div>
                        <div class="sk-text sk-shimmer" style="width:75px;height:14px"></div>
                    </div>
                    <div style="border-top:1px solid rgba(255,255,255,0.06);padding-top:14px;display:flex;justify-content:space-between">
                        <div class="sk-text sk-shimmer" style="width:100px;height:12px"></div>
                        <div class="sk-text sk-shimmer" style="width:90px;height:12px"></div>
                    </div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        <!-- Pagination skeleton -->
        <div class="sr-pagination">
            <?php for ($p = 0; $p < 5; $p++): ?>
            <div class="sk-text sk-shimmer" style="width:40px;height:40px;border-radius:8px"></div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js" defer></script>
<script>
(function() {
    'use strict';

    // ═══════════════════════════════════════════════════
    // Utilities
    // ═══════════════════════════════════════════════════
    function debounce(fn, ms) {
        var t;
        return function() {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function() { fn.apply(ctx, args); }, ms);
        };
    }

    function formatNumber(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // ═══════════════════════════════════════════════════
    // State
    // ═══════════════════════════════════════════════════
    var _abortCtrl  = null;
    var _firstLoad  = true;
    var _currentPage = <?= $page ?>;

    // Slider boundary defaults (from DB) — price params are omitted when at these defaults
    var _PRICE_MIN_BOUND = <?= $min_bound ?>;
    var _PRICE_MAX_BOUND = <?= $max_bound ?>;

    // ═══════════════════════════════════════════════════
    // Cached DOM References (stable elements)
    // ═══════════════════════════════════════════════════
    var _els = {
        resultsArea:    document.getElementById('resultsArea'),
        drawer:         document.getElementById('filterDrawer'),
        overlay:        document.getElementById('drawerOverlay'),
        citySelect:     document.getElementById('citySelect'),
        typeSelect:     document.getElementById('typeSelect'),
        statusInput:    document.getElementById('statusInput'),
        bedroomsInput:  document.getElementById('bedroomsInput'),
        bathroomsInput: document.getElementById('bathroomsInput'),
        minPriceInput:  document.getElementById('minPriceInput'),
        maxPriceInput:  document.getElementById('maxPriceInput'),
        categoryInput:  document.getElementById('categoryInput'),
        searchInput:    document.getElementById('searchInput'),
        postedBySelect: document.getElementById('postedBySelect'),
        priceMinSlider: document.getElementById('priceMinSlider'),
        priceMaxSlider: document.getElementById('priceMaxSlider'),
        priceMinDisplay:document.getElementById('priceMinDisplay'),
        priceMaxDisplay:document.getElementById('priceMaxDisplay'),
        priceRange:     document.getElementById('priceSliderRange'),
        activeFilters:  document.getElementById('activeFiltersBar')
    };

    // ═══════════════════════════════════════════════════
    // Drawer
    // ═══════════════════════════════════════════════════
    function toggleDrawer() {
        var opening = !_els.drawer.classList.contains('open');
        _els.drawer.classList.toggle('open', opening);
        _els.overlay.classList.toggle('open', opening);
        document.documentElement.style.overflow = opening ? 'hidden' : '';
        document.body.style.overflow = opening ? 'hidden' : '';
    }

    function applyAndClose() {
        updateResults();
        toggleDrawer();
    }

    // ═══════════════════════════════════════════════════
    // Query Builder
    // ═══════════════════════════════════════════════════
    function buildQuery(pg) {
        var p = new URLSearchParams();
        if (_els.citySelect.value)                     p.set('city', _els.citySelect.value);
        if (_els.typeSelect.value)                     p.set('property_type', _els.typeSelect.value);
        if (_els.statusInput.value)                    p.set('status', _els.statusInput.value);
        if (+_els.bedroomsInput.value > 0)             p.set('bedrooms', _els.bedroomsInput.value);
        if (+_els.bathroomsInput.value > 0)            p.set('bathrooms', _els.bathroomsInput.value);
        if (+_els.minPriceInput.value > _PRICE_MIN_BOUND)  p.set('min_price', _els.minPriceInput.value);
        if (+_els.maxPriceInput.value < _PRICE_MAX_BOUND)   p.set('max_price', _els.maxPriceInput.value);
        var cat = _els.categoryInput.value;
        if (cat && cat !== 'all')                      p.set('category', cat);
        var q = _els.searchInput.value.trim();
        if (q)                                         p.set('q', q);
        if (+_els.postedBySelect.value > 0)            p.set('posted_by', _els.postedBySelect.value);
        if (pg > 1) p.set('page', pg);
        p.set('partial', 'grid');
        return p.toString();
    }

    // ═══════════════════════════════════════════════════
    // AJAX Results — with AbortController + smooth transitions
    // ═══════════════════════════════════════════════════
    function updateResults(page) {
        var pg = (typeof page === 'number') ? page : 1;
        _currentPage = pg;

        // Cancel any in-flight request
        if (_abortCtrl) _abortCtrl.abort();
        _abortCtrl = new AbortController();

        var area = _els.resultsArea;
        var qs = buildQuery(pg);

        // Update browser URL (strip partial param for display)
        var display = new URLSearchParams(qs);
        display.delete('partial');
        var cleanUrl = location.pathname + (display.toString() ? '?' + display.toString() : '');
        history.replaceState(null, '', cleanUrl);

        // Loading state: dim current content (skeleton stays full opacity on first load)
        if (!_firstLoad) {
            area.style.opacity = '0.4';
            area.style.pointerEvents = 'none';
        }

        // Lock height to prevent scroll jump during content swap
        area.style.minHeight = area.offsetHeight + 'px';

        var signal = _abortCtrl.signal;

        fetch('search_results.php?' + qs, { signal: signal })
            .then(function(res) { return res.text(); })
            .then(function(html) {
                // Fade out completely before swapping
                area.style.transition = 'opacity 0.15s ease';
                area.style.opacity = '0';

                setTimeout(function() {
                    // Swap content while invisible
                    area.innerHTML = html;

                    // Fade in new content
                    area.style.transition = 'opacity 0.3s ease';
                    // Force reflow so browser recognises the transition start value
                    void area.offsetHeight;
                    area.style.opacity = '1';
                    area.style.pointerEvents = '';

                    // Release locked height after transition
                    setTimeout(function() { area.style.minHeight = ''; }, 350);

                    _firstLoad = false;

                    // Update page state from AJAX response
                    var tpEl = document.getElementById('srTotalPages');
                    var cpEl = document.getElementById('srCurrentPage');
                    if (tpEl && cpEl) _currentPage = parseInt(cpEl.value) || pg;

                    syncCategoryButtons();
                    renderActiveFilters();
                    updateFilterBadge();

                }, _firstLoad ? 60 : 150);
            })
            .catch(function(e) {
                if (e.name === 'AbortError') return; // Intentional cancellation — ignore
                console.error('Search fetch error:', e);
                area.style.opacity = '1';
                area.style.pointerEvents = '';
                area.style.minHeight = '';
            });
    }

    // ═══════════════════════════════════════════════════
    // Pagination
    // ═══════════════════════════════════════════════════
    function goToPage(pg) {
        var tp = parseInt((document.getElementById('srTotalPages') || {}).value || '1');
        if (pg < 1 || pg > tp) return;
        _currentPage = pg;
        updateResults(pg);
        _els.resultsArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ═══════════════════════════════════════════════════
    // Filter Helpers
    // ═══════════════════════════════════════════════════
    function setFilter(name, value) {
        var input = document.getElementById(name + 'Input');
        if (!input) return;
        input.value = value;
        refreshButtonGroup(name + 'Input');
        updateResults();
    }

    function refreshButtonGroup(inputId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        var val = String(input.value);
        var group = input.closest('.filter-group');
        if (!group) return;
        group.querySelectorAll('.filter-btn').forEach(function(btn) {
            var text = btn.textContent.trim();
            var btnVal = text.replace('+', '').trim();
            if (text.toLowerCase() === 'any') btnVal = '0';
            if (text.toLowerCase() === 'all') btnVal = '';
            btn.classList.toggle('active', String(btnVal) === val);
        });
    }

    function setCategoryFilter(cat) {
        _els.categoryInput.value = cat;
        syncCategoryButtons();
        updateResults();
    }

    function syncCategoryButtons() {
        var current = _els.categoryInput.value;
        document.querySelectorAll('.category-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.category === current);
        });
    }

    function clearCategory() {
        _els.categoryInput.value = 'all';
        document.querySelectorAll('.category-btn').forEach(function(btn) {
            btn.classList.remove('active');
        });
    }

    // ═══════════════════════════════════════════════════
    // Active Filter Chips
    // ═══════════════════════════════════════════════════
    function renderActiveFilters() {
        var bar = _els.activeFilters;
        if (!bar) return;
        var chips = [];
        var cat = _els.categoryInput.value;
        if (cat && cat !== 'all') {
            var names = { most_viewed: 'Most Viewed', most_liked: 'Most Liked', most_beds: 'Most Beds', for_sale: 'For Sale', for_rent: 'For Rent' };
            chips.push({ label: names[cat] || cat, clear: function() { clearCategory(); updateResults(); } });
        }
        if (_els.citySelect.value) chips.push({ label: 'City: ' + _els.citySelect.value, clear: function() { _els.citySelect.value = ''; updateResults(); } });
        if (_els.typeSelect.value) chips.push({ label: 'Type: ' + _els.typeSelect.value, clear: function() { _els.typeSelect.value = ''; updateResults(); } });
        if (_els.statusInput.value) chips.push({ label: _els.statusInput.value, clear: function() { setFilter('status', ''); } });
        if (+_els.bedroomsInput.value > 0) chips.push({ label: _els.bedroomsInput.value + '+ Beds', clear: function() { setFilter('bedrooms', 0); } });
        if (+_els.bathroomsInput.value > 0) chips.push({ label: _els.bathroomsInput.value + '+ Baths', clear: function() { setFilter('bathrooms', 0); } });
        var minP = +_els.minPriceInput.value;
        var maxP = +_els.maxPriceInput.value;
        var minB = +_els.priceMinSlider.min;
        var maxB = +_els.priceMaxSlider.max;
        if (minP > minB || maxP < maxB) chips.push({ label: '₱' + formatNumber(minP) + ' – ₱' + formatNumber(maxP), clear: function() { resetPrice(); updateResults(); } });
        if (+_els.postedBySelect.value > 0) {
            var agName = _els.postedBySelect.options[_els.postedBySelect.selectedIndex] ? _els.postedBySelect.options[_els.postedBySelect.selectedIndex].text : 'Agent';
            chips.push({ label: 'By: ' + agName, clear: function() { _els.postedBySelect.value = '0'; updateResults(); } });
        }

        bar.innerHTML = chips.map(function(c, i) {
            return '<span class="filter-chip">' + c.label + ' <span class="chip-remove" data-chip="' + i + '"><i class="bi bi-x"></i></span></span>';
        }).join('');

        // Store clear functions for event delegation
        bar._chipClearFns = chips.map(function(c) { return c.clear; });
    }

    function updateFilterBadge() {
        var count = 0;
        var cat = _els.categoryInput.value;
        if (cat && cat !== 'all') count++;
        if (_els.citySelect.value) count++;
        if (_els.typeSelect.value) count++;
        if (_els.statusInput.value) count++;
        if (+_els.bedroomsInput.value > 0) count++;
        if (+_els.bathroomsInput.value > 0) count++;
        if (+_els.postedBySelect.value > 0) count++;
        var minP = +_els.minPriceInput.value;
        var maxP = +_els.maxPriceInput.value;
        if (minP > +_els.priceMinSlider.min || maxP < +_els.priceMaxSlider.max) count++;

        document.querySelectorAll('.filter-badge-count').forEach(function(el) {
            el.textContent = count;
            el.style.display = count > 0 ? 'inline-flex' : 'none';
        });
    }

    // ═══════════════════════════════════════════════════
    // Price Slider
    // ═══════════════════════════════════════════════════
    function updatePriceSliderRange() {
        var min = _els.priceMinSlider;
        var max = _els.priceMaxSlider;
        var range = _els.priceRange;
        if (!min || !max || !range) return;
        var minPct = ((min.value - min.min) / (min.max - min.min)) * 100;
        var maxPct = ((max.value - max.min) / (max.max - max.min)) * 100;
        range.style.left = minPct + '%';
        range.style.width = (maxPct - minPct) + '%';
    }

    function resetPrice() {
        var min = _els.priceMinSlider;
        var max = _els.priceMaxSlider;
        min.value = min.min;
        max.value = max.max;
        _els.minPriceInput.value = min.min;
        _els.maxPriceInput.value = max.max;
        _els.priceMinDisplay.value = formatNumber(+min.min);
        _els.priceMaxDisplay.value = formatNumber(+max.max);
        updatePriceSliderRange();
    }

    function clearFilters() {
        _els.citySelect.value = '';
        _els.typeSelect.value = '';
        _els.statusInput.value = '';
        _els.bedroomsInput.value = 0;
        _els.bathroomsInput.value = 0;
        _els.searchInput.value = '';
        _els.postedBySelect.value = '0';
        clearCategory();
        resetPrice();
        refreshButtonGroup('statusInput');
        refreshButtonGroup('bedroomsInput');
        refreshButtonGroup('bathroomsInput');
        updateResults();
    }

    // ═══════════════════════════════════════════════════
    // Event Delegation (handles dynamically-loaded content)
    // ═══════════════════════════════════════════════════

    // Pagination buttons (data-page attribute, loaded via AJAX)
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.sr-page-btn[data-page]');
        if (btn && !btn.disabled) {
            e.preventDefault();
            goToPage(parseInt(btn.dataset.page));
        }
    });

    // Filter toggle buttons in AJAX-loaded results header
    document.addEventListener('click', function(e) {
        if (e.target.closest('[data-action="toggle-drawer"]')) {
            toggleDrawer();
        }
    });

    // Filter chip removal
    document.addEventListener('click', function(e) {
        var chip = e.target.closest('.chip-remove[data-chip]');
        if (chip) {
            var bar = _els.activeFilters;
            var idx = parseInt(chip.dataset.chip);
            if (bar._chipClearFns && bar._chipClearFns[idx]) {
                bar._chipClearFns[idx]();
            }
        }
    });

    // Property card view-count tracking + navigation
    document.addEventListener('click', function(e) {
        var link = e.target.closest('.property-card-link');
        if (!link) return;
        e.preventDefault();
        var href = link.getAttribute('href');
        var match = href.match(/id=(\d+)/);
        if (!match) { window.location.href = href; return; }

        var propId = parseInt(match[1]);
        var viewed = [];
        try { viewed = JSON.parse(localStorage.getItem('viewedProperties') || '[]'); } catch(x) {}

        if (!viewed.includes(propId)) {
            var viewsSpan = link.querySelector('.stat-badge.views span');
            fetch('increment_property_view.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'property_id=' + propId
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && viewsSpan) viewsSpan.textContent = data.views.toLocaleString();
                if (data.success) {
                    viewed.push(propId);
                    localStorage.setItem('viewedProperties', JSON.stringify(viewed));
                }
            })
            .catch(function() {})
            .finally(function() { setTimeout(function() { window.location.href = href; }, 150); });
        } else {
            window.location.href = href;
        }
    });

    // ═══════════════════════════════════════════════════
    // Expose functions to inline handlers (drawer buttons)
    // ═══════════════════════════════════════════════════
    window.toggleDrawer      = toggleDrawer;
    window.applyAndClose     = applyAndClose;
    window.setFilter         = setFilter;
    window.setCategoryFilter = setCategoryFilter;
    window.clearFilters      = clearFilters;

    // ═══════════════════════════════════════════════════
    // Initialization
    // ═══════════════════════════════════════════════════
    var debouncedUpdate = debounce(function() { updateResults(); }, 250);
    var GAP = 500000;

    // Price sliders
    if (_els.priceMinSlider) {
        _els.priceMinSlider.addEventListener('input', function() {
            var min = parseInt(this.value);
            var max = parseInt(_els.priceMaxSlider.value);
            if (min > max - GAP) { min = max - GAP; this.value = min; }
            _els.minPriceInput.value = min;
            _els.priceMinDisplay.value = formatNumber(min);
            updatePriceSliderRange();
            debouncedUpdate();
        });
    }
    if (_els.priceMaxSlider) {
        _els.priceMaxSlider.addEventListener('input', function() {
            var max = parseInt(this.value);
            var min = parseInt(_els.priceMinSlider.value);
            if (max < min + GAP) { max = min + GAP; this.value = max; }
            _els.maxPriceInput.value = max;
            _els.priceMaxDisplay.value = formatNumber(max);
            updatePriceSliderRange();
            debouncedUpdate();
        });
    }
    updatePriceSliderRange();

    // Select dropdowns — single change listener each
    _els.citySelect.addEventListener('change', function() { updateResults(); });
    _els.typeSelect.addEventListener('change', function() { updateResults(); });
    _els.postedBySelect.addEventListener('change', function() { updateResults(); });

    // Search input — debounced, triggers after 3+ chars or when cleared
    var debouncedSearch = debounce(function() { updateResults(); }, 400);
    _els.searchInput.addEventListener('input', function() {
        if (this.value.length >= 3 || this.value.length === 0) debouncedSearch();
    });

    // Drawer overlay + Escape key
    _els.overlay.addEventListener('click', toggleDrawer);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && _els.drawer.classList.contains('open')) toggleDrawer();
    });

    // Render initial UI state
    renderActiveFilters();
    updateFilterBadge();
    syncCategoryButtons();

    // Initial AJAX load — replaces skeleton with real property data
    updateResults(<?= $page ?>);
})();
</script>

</body>
</html>
