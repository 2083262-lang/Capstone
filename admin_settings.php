<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch amenities
$amenities = [];
$r = $conn->query("SELECT amenity_id, amenity_name FROM amenities ORDER BY amenity_name ASC");
if ($r) { while ($row = $r->fetch_assoc()) $amenities[] = $row; }

// Fetch specializations
$specializations = [];
$r = $conn->query("SELECT specialization_id, specialization_name FROM specializations ORDER BY specialization_name ASC");
if ($r) { while ($row = $r->fetch_assoc()) $specializations[] = $row; }

$total_amenities = count($amenities);
$total_specializations = count($specializations);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ===== SAME LAYOUT AS property.php ===== */
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-light); color: #212529; }
        .admin-sidebar { background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%); color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; z-index: 1000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .admin-content { margin-left: 290px; padding: 2rem; min-height: 100vh; max-width: 1800px; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; padding: 1.5rem; } }
        @media (max-width: 768px) { .admin-content { margin-left: 0 !important; padding: 1rem; } }

        .admin-content {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --card-bg: #ffffff;
            --text-primary: #212529;
            --text-secondary: #6c757d;
        }

        /* ===== PAGE HEADER ===== */
        .page-header { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(ellipse at top right, rgba(37,99,235,0.04) 0%, transparent 50%), radial-gradient(ellipse at bottom left, rgba(212,175,55,0.03) 0%, transparent 50%); pointer-events: none; }
        .page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; }
        .page-header .subtitle { color: var(--text-secondary); font-size: 0.95rem; }
        .page-header .header-badge { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.85rem; border-radius: 2px; text-transform: uppercase; letter-spacing: 0.5px; }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1.75rem 1.5rem; position: relative; overflow: hidden; transition: all 0.3s ease; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--blue), transparent); opacity: 0; transition: opacity 0.3s ease; }
        .kpi-card:hover { border-color: rgba(37,99,235,0.25); box-shadow: 0 8px 32px rgba(37,99,235,0.08); transform: translateY(-3px); }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card .kpi-icon { width: 40px; height: 40px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 0.75rem; }
        .kpi-icon.gold { background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.15)); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); }
        .kpi-icon.blue { background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(37,99,235,0.12)); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .kpi-icon.green { background: linear-gradient(135deg, rgba(34,197,94,0.06), rgba(34,197,94,0.12)); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .kpi-icon.cyan { background: linear-gradient(135deg, rgba(6,182,212,0.06), rgba(6,182,212,0.12)); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .kpi-card .kpi-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 0.25rem; }
        .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }
        @media (max-width: 992px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .kpi-grid { grid-template-columns: 1fr; } }

        /* ===== SETTINGS TABS ===== */
        .settings-tabs { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; margin-bottom: 1.5rem; overflow: hidden; }
        .settings-tabs .nav-tabs { border: none; padding: 0.5rem 1rem 0; gap: 0; background: rgba(37,99,235,0.02); }
        .settings-tabs .nav-link { border: none; border-radius: 0; padding: 1rem 1.5rem; font-weight: 600; font-size: 0.88rem; color: var(--text-secondary); transition: all 0.3s ease; position: relative; background: transparent; display: flex; align-items: center; gap: 0.5rem; }
        .settings-tabs .nav-link:hover { color: var(--blue); background: rgba(37,99,235,0.04); }
        .settings-tabs .nav-link.active { color: var(--blue); background: transparent; }
        .settings-tabs .nav-link.active::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); }
        .settings-tabs .tab-badge { font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 10px; background: rgba(37,99,235,0.1); color: var(--blue); }
        .settings-tabs .tab-content { padding: 1.5rem; }

        /* ===== SETTINGS CARD ===== */
        .settings-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; }
        .settings-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(37,99,235,0.08); display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; position: relative; }
        .settings-card-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(212,175,55,0.3), transparent); }
        .settings-card-header .header-left { display: flex; align-items: center; gap: 0.75rem; }
        .settings-card-header .header-left i { font-size: 1.1rem; color: var(--blue); }
        .settings-card-header .header-left h3 { font-size: 1rem; font-weight: 700; color: var(--text-primary); margin: 0; }
        .settings-card-header .header-left p { font-size: 0.8rem; color: var(--text-secondary); margin: 0; }
        .settings-card-body { padding: 1.5rem; }

        /* ===== ADD FORM ===== */
        .add-form { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; }
        .add-form input { flex: 1; border: 1px solid rgba(37,99,235,0.2); border-radius: 4px; padding: 0.65rem 1rem; font-size: 0.9rem; font-family: 'Inter', sans-serif; transition: all 0.2s ease; }
        .add-form input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .add-form input::placeholder { color: #94a3b8; }

        /* ===== BUTTONS ===== */
        .btn-gold { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; border: none; font-weight: 700; font-size: 0.85rem; padding: 0.6rem 1.25rem; border-radius: 4px; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; }
        .btn-gold:hover { background: linear-gradient(135deg, var(--gold), var(--gold-light)); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(212,175,55,0.3); }
        .btn-blue { background: linear-gradient(135deg, var(--blue-dark), var(--blue)); color: #fff; border: none; font-weight: 700; font-size: 0.85rem; padding: 0.6rem 1.25rem; border-radius: 4px; transition: all 0.3s ease; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-blue:hover { background: linear-gradient(135deg, var(--blue), var(--blue-light)); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(37,99,235,0.3); }

        /* ===== ITEM LIST (tags/chips style) ===== */
        .item-search { position: relative; margin-bottom: 1rem; }
        .item-search input { width: 100%; border: 1px solid rgba(37,99,235,0.15); border-radius: 4px; padding: 0.6rem 1rem 0.6rem 2.5rem; font-size: 0.88rem; font-family: 'Inter', sans-serif; }
        .item-search input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); }
        .item-search i { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; }

        .items-grid { display: flex; flex-wrap: wrap; gap: 0.5rem; max-height: 400px; overflow-y: auto; padding: 0.25rem; }
        .items-grid::-webkit-scrollbar { width: 5px; }
        .items-grid::-webkit-scrollbar-track { background: transparent; }
        .items-grid::-webkit-scrollbar-thumb { background: rgba(37,99,235,0.2); border-radius: 3px; }

        .item-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.85rem;
            background: rgba(37, 99, 235, 0.04);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-primary);
            transition: all 0.2s ease;
        }
        .item-chip:hover { border-color: rgba(37, 99, 235, 0.3); background: rgba(37, 99, 235, 0.08); }
        .item-chip .chip-name { white-space: nowrap; }
        .item-chip .chip-delete {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: none;
            cursor: pointer;
            font-size: 0.7rem;
            transition: all 0.2s ease;
            padding: 0;
        }
        .item-chip .chip-delete:hover { background: #dc2626; color: #fff; transform: scale(1.15); }

        .item-count { font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.75rem; }

        /* ===== TOAST ===== */
        .settings-toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 9999;
            min-width: 300px;
        }
        .settings-toast .toast {
            border: none;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        }
        .settings-toast .toast-header { border-radius: 8px 8px 0 0; font-weight: 600; }
        .toast-success .toast-header { background: rgba(34,197,94,0.1); color: #16a34a; border-bottom: 1px solid rgba(34,197,94,0.15); }
        .toast-error .toast-header { background: rgba(239,68,68,0.1); color: #dc2626; border-bottom: 1px solid rgba(239,68,68,0.15); }

        .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--text-secondary); }
        .empty-state i { font-size: 2rem; opacity: 0.3; display: block; margin-bottom: 0.5rem; }



        @media (max-width: 768px) {
            .add-form { flex-direction: column; }
            .settings-tabs .nav-link { padding: 0.75rem 1rem; font-size: 0.82rem; }
        }

        /* Delete confirmation */
        .delete-confirm-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .delete-confirm-box {
            background: #fff;
            border-radius: 8px;
            padding: 2rem;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 16px 48px rgba(0,0,0,0.2);
            text-align: center;
        }
        .delete-confirm-box h5 { font-weight: 700; margin-bottom: 0.5rem; }
        .delete-confirm-box p { color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem; }
        .delete-confirm-box .btn-group-confirm { display: flex; gap: 0.75rem; justify-content: center; }
        .btn-cancel-del { background: #f1f5f9; color: var(--text-primary); border: 1px solid #e2e8f0; border-radius: 4px; padding: 0.5rem 1.25rem; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-cancel-del:hover { background: #e2e8f0; }
        .btn-confirm-del { background: #dc2626; color: #fff; border: none; border-radius: 4px; padding: 0.5rem 1.25rem; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-confirm-del:hover { background: #b91c1c; }
    </style>
</head>
<body>
    <?php $active_page = 'admin_settings.php'; include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1>System Settings</h1>
                    <p class="subtitle">Manage amenities, specializations, and system configuration</p>
                </div>
                <span class="header-badge"><i class="bi bi-gear me-1"></i> Settings</span>
            </div>
        </div>

        <!-- KPI Stats -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-check2-square"></i></div>
                <div class="kpi-label">Amenities</div>
                <div class="kpi-value"><?php echo $total_amenities; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon cyan"><i class="bi bi-tags"></i></div>
                <div class="kpi-label">Specializations</div>
                <div class="kpi-value"><?php echo $total_specializations; ?></div>
            </div>
        </div>

        <!-- Settings Tabs -->
        <div class="settings-tabs">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-amenities" type="button" role="tab">
                        <i class="bi bi-check2-square"></i> Amenities
                        <span class="tab-badge"><?php echo $total_amenities; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-specializations" type="button" role="tab">
                        <i class="bi bi-tags"></i> Specializations
                        <span class="tab-badge"><?php echo $total_specializations; ?></span>
                    </button>
                </li>

            </ul>

            <div class="tab-content">
                <!-- === AMENITIES TAB === -->
                <div class="tab-pane fade show active" id="tab-amenities" role="tabpanel">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="header-left">
                                <i class="bi bi-check2-square"></i>
                                <div>
                                    <h3>Property Amenities</h3>
                                    <p>Manage the amenities available for property listings</p>
                                </div>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="add-form" id="amenityAddForm">
                                <input type="text" id="amenityInput" placeholder="Enter a new amenity name..." maxlength="100" autocomplete="off">
                                <button class="btn-blue" onclick="addItem('amenity')"><i class="bi bi-plus-lg"></i> Add Amenity</button>
                            </div>

                            <div class="item-search">
                                <i class="bi bi-search"></i>
                                <input type="text" id="amenitySearch" placeholder="Search amenities..." oninput="filterItems('amenity')">
                            </div>

                            <div class="items-grid" id="amenityGrid">
                                <?php foreach ($amenities as $a): ?>
                                    <div class="item-chip" data-id="<?php echo $a['amenity_id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($a['amenity_name'])); ?>">
                                        <span class="chip-name"><?php echo htmlspecialchars($a['amenity_name']); ?></span>
                                        <button class="chip-delete" title="Delete" onclick="confirmDelete('amenity', <?php echo $a['amenity_id']; ?>, '<?php echo htmlspecialchars(addslashes($a['amenity_name'])); ?>')"><i class="bi bi-x"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="item-count" id="amenityCount"><?php echo $total_amenities; ?> amenities total</div>
                        </div>
                    </div>
                </div>

                <!-- === SPECIALIZATIONS TAB === -->
                <div class="tab-pane fade" id="tab-specializations" role="tabpanel">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="header-left">
                                <i class="bi bi-tags"></i>
                                <div>
                                    <h3>Agent Specializations</h3>
                                    <p>Manage the specialization options available for agents</p>
                                </div>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="add-form" id="specAddForm">
                                <input type="text" id="specInput" placeholder="Enter a new specialization name..." maxlength="100" autocomplete="off">
                                <button class="btn-blue" onclick="addItem('spec')"><i class="bi bi-plus-lg"></i> Add Specialization</button>
                            </div>

                            <div class="item-search">
                                <i class="bi bi-search"></i>
                                <input type="text" id="specSearch" placeholder="Search specializations..." oninput="filterItems('spec')">
                            </div>

                            <div class="items-grid" id="specGrid">
                                <?php foreach ($specializations as $s): ?>
                                    <div class="item-chip" data-id="<?php echo $s['specialization_id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($s['specialization_name'])); ?>">
                                        <span class="chip-name"><?php echo htmlspecialchars($s['specialization_name']); ?></span>
                                        <button class="chip-delete" title="Delete" onclick="confirmDelete('spec', <?php echo $s['specialization_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['specialization_name'])); ?>')"><i class="bi bi-x"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="item-count" id="specCount"><?php echo $total_specializations; ?> specializations total</div>
                        </div>
                    </div>
                </div>



            </div>
        </div>
    </div>

    <!-- Delete Confirmation (dynamic) -->
    <div id="deleteConfirmOverlay" class="delete-confirm-overlay" style="display:none;">
        <div class="delete-confirm-box">
            <div style="width:48px;height:48px;border-radius:50%;background:rgba(239,68,68,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <i class="bi bi-exclamation-triangle" style="font-size:1.3rem;color:#dc2626;"></i>
            </div>
            <h5>Delete <span id="deleteItemType"></span>?</h5>
            <p>Are you sure you want to delete "<strong id="deleteItemName"></strong>"? This action cannot be undone.</p>
            <div class="btn-group-confirm">
                <button class="btn-cancel-del" onclick="closeDeleteConfirm()">Cancel</button>
                <button class="btn-confirm-del" id="deleteConfirmBtn" onclick="executeDelete()"><i class="bi bi-trash me-1"></i> Delete</button>
            </div>
        </div>
    </div>

    <!-- Toast container -->
    <div class="settings-toast" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let pendingDelete = { type: null, id: null, name: null };

    // ===== ADD ITEM =====
    function addItem(type) {
        const inputId = type === 'amenity' ? 'amenityInput' : 'specInput';
        const input = document.getElementById(inputId);
        const name = input.value.trim();

        if (!name) { input.focus(); return; }
        if (name.length > 100) { showToast('error', 'Name must be 100 characters or less.'); return; }

        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('type', type === 'amenity' ? 'amenity' : 'specialization');
        fd.append('name', name);

        fetch('admin_settings_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    input.value = '';
                    showToast('success', data.message);
                    // Add chip dynamically
                    const grid = document.getElementById(type === 'amenity' ? 'amenityGrid' : 'specGrid');
                    const chip = document.createElement('div');
                    chip.className = 'item-chip';
                    chip.dataset.id = data.id;
                    chip.dataset.name = name.toLowerCase();
                    chip.innerHTML = `<span class="chip-name">${escapeHtml(name)}</span>
                        <button class="chip-delete" title="Delete" onclick="confirmDelete('${type}', ${data.id}, '${escapeHtml(name).replace(/'/g, "\\'")}')"><i class="bi bi-x"></i></button>`;
                    grid.appendChild(chip);
                    updateCount(type);
                    updateBadge(type);
                } else {
                    showToast('error', data.message || 'Failed to add.');
                }
            })
            .catch(() => showToast('error', 'Network error. Please try again.'));
    }

    // Allow Enter key to add
    document.getElementById('amenityInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addItem('amenity'); } });
    document.getElementById('specInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addItem('spec'); } });

    // ===== DELETE =====
    function confirmDelete(type, id, name) {
        pendingDelete = { type, id, name };
        document.getElementById('deleteItemType').textContent = type === 'amenity' ? 'Amenity' : 'Specialization';
        document.getElementById('deleteItemName').textContent = name;
        document.getElementById('deleteConfirmOverlay').style.display = 'flex';
    }

    function closeDeleteConfirm() {
        document.getElementById('deleteConfirmOverlay').style.display = 'none';
        pendingDelete = { type: null, id: null, name: null };
    }

    function executeDelete() {
        if (!pendingDelete.type || !pendingDelete.id) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('type', pendingDelete.type === 'amenity' ? 'amenity' : 'specialization');
        fd.append('id', pendingDelete.id);

        fetch('admin_settings_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const grid = document.getElementById(pendingDelete.type === 'amenity' ? 'amenityGrid' : 'specGrid');
                    const chip = grid.querySelector(`.item-chip[data-id="${pendingDelete.id}"]`);
                    if (chip) {
                        chip.style.transition = 'all 0.3s ease';
                        chip.style.opacity = '0';
                        chip.style.transform = 'scale(0.8)';
                        setTimeout(() => { chip.remove(); updateCount(pendingDelete.type); updateBadge(pendingDelete.type); }, 300);
                    }
                    showToast('success', data.message);
                } else {
                    showToast('error', data.message || 'Failed to delete.');
                }
                closeDeleteConfirm();
            })
            .catch(() => { showToast('error', 'Network error.'); closeDeleteConfirm(); });
    }

    // Close overlay on background click
    document.getElementById('deleteConfirmOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteConfirm();
    });

    // ===== FILTER/SEARCH =====
    function filterItems(type) {
        const searchId = type === 'amenity' ? 'amenitySearch' : 'specSearch';
        const gridId = type === 'amenity' ? 'amenityGrid' : 'specGrid';
        const q = document.getElementById(searchId).value.toLowerCase().trim();
        const chips = document.getElementById(gridId).querySelectorAll('.item-chip');
        let visible = 0;
        chips.forEach(chip => {
            const match = chip.dataset.name.includes(q);
            chip.style.display = match ? '' : 'none';
            if (match) visible++;
        });
    }

    // ===== UTILITIES =====
    function updateCount(type) {
        const gridId = type === 'amenity' ? 'amenityGrid' : 'specGrid';
        const countId = type === 'amenity' ? 'amenityCount' : 'specCount';
        const label = type === 'amenity' ? 'amenities' : 'specializations';
        const total = document.getElementById(gridId).querySelectorAll('.item-chip').length;
        document.getElementById(countId).textContent = total + ' ' + label + ' total';
    }

    function updateBadge(type) {
        const gridId = type === 'amenity' ? 'amenityGrid' : 'specGrid';
        const total = document.getElementById(gridId).querySelectorAll('.item-chip').length;
        // Update the tab badge
        const tabIndex = type === 'amenity' ? 0 : 1;
        const badges = document.querySelectorAll('.settings-tabs .tab-badge');
        if (badges[tabIndex]) badges[tabIndex].textContent = total;
    }

    function showToast(type, message) {
        const container = document.getElementById('toastContainer');
        const id = 'toast_' + Date.now();
        const html = `<div id="${id}" class="toast ${type === 'success' ? 'toast-success' : 'toast-error'}" role="alert" data-bs-delay="4000">
            <div class="toast-header">
                <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'} me-2"></i>
                <strong class="me-auto">${type === 'success' ? 'Success' : 'Error'}</strong>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body" style="font-size:0.88rem;">${escapeHtml(message)}</div>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
        const el = document.getElementById(id);
        const toast = new bootstrap.Toast(el);
        toast.show();
        el.addEventListener('hidden.bs.toast', () => el.remove());
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    </script>
</body>
</html>
