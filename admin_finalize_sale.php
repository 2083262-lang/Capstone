<?php
session_start();
require_once __DIR__ . '/connection.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Invalid request method']);
        exit;
    }

    $property_id = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
    $agent_id = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : 0;
    $final_sale_price = isset($_POST['final_sale_price']) ? trim($_POST['final_sale_price']) : '';
    $buyer_name = isset($_POST['buyer_name']) ? trim($_POST['buyer_name']) : '';
    $buyer_email = isset($_POST['buyer_email']) ? trim($_POST['buyer_email']) : '';
    $buyer_contact = isset($_POST['buyer_contact']) ? trim($_POST['buyer_contact']) : '';
    $commission_percentage = isset($_POST['commission_percentage']) ? trim($_POST['commission_percentage']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    $errors = [];
    if ($property_id <= 0) $errors[] = 'Invalid property.';
    if ($agent_id <= 0) $errors[] = 'Invalid agent.';

    // Normalize numbers
    $final_sale_price = is_numeric(str_replace([','], '', $final_sale_price)) ? (float)str_replace([','], '', $final_sale_price) : null;
    $commission_percentage = is_numeric($commission_percentage) ? (float)$commission_percentage : null;

    if ($final_sale_price === null || $final_sale_price <= 0) $errors[] = 'Final sale price must be a positive number.';
    if ($commission_percentage === null || $commission_percentage < 0 || $commission_percentage > 100) $errors[] = 'Commission % must be between 0 and 100.';
    if ($buyer_email !== '' && !filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Buyer email is invalid.';

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // Ensure property exists and is already SOLD
    $propSql = "SELECT Status, is_locked, sold_by_agent FROM property WHERE property_ID = ? LIMIT 1";
    $ps = $conn->prepare($propSql);
    $ps->bind_param('i', $property_id);
    $ps->execute();
    $propRes = $ps->get_result();
    $property = $propRes->fetch_assoc();
    $ps->close();

    if (!$property) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Property not found.']);
        exit;
    }
    
    if (strcasecmp($property['Status'], 'Sold') !== 0) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Property must be SOLD before finalizing sale.']);
        exit;
    }

    // If agent_id not matching, prefer the property.sold_by_agent for safety
    $agent_id_from_property = (int)$property['sold_by_agent'];
    if ($agent_id_from_property > 0 && $agent_id_from_property !== $agent_id) {
        $agent_id = $agent_id_from_property;
    }

    $commission_amount = round($final_sale_price * ($commission_percentage / 100), 2);

    $conn->begin_transaction();

    // Check if a finalized sale already exists for this property
    $existingSaleId = null;
    $fsCheck = $conn->prepare("SELECT sale_id FROM finalized_sales WHERE property_id = ? ORDER BY finalized_at DESC LIMIT 1");
    $fsCheck->bind_param('i', $property_id);
    $fsCheck->execute();
    $fsRes = $fsCheck->get_result();
    if ($row = $fsRes->fetch_assoc()) {
        $existingSaleId = (int)$row['sale_id'];
    }
    $fsCheck->close();

    $now = date('Y-m-d H:i:s');

    if ($existingSaleId) {
        // Update existing finalized sale record
        $upd = $conn->prepare("UPDATE finalized_sales
            SET agent_id = ?, buyer_name = ?, buyer_email = ?, buyer_contact = ?,
                final_sale_price = ?, sale_date = ?, commission_amount = ?, commission_percentage = ?,
                additional_notes = ?, finalized_by = ?, finalized_at = ?, is_locked = 1
            WHERE sale_id = ?");
        $upd->bind_param(
            'isssdsdsisii',
            $agent_id,
            $buyer_name,
            $buyer_email,
            $buyer_contact,
            $final_sale_price,
            $now,
            $commission_amount,
            $commission_percentage,
            $notes,
            $_SESSION['account_id'],
            $now,
            $existingSaleId
        );
        if (!$upd->execute()) {
            throw new Exception('Failed to update finalized sale.');
        }
        $upd->close();
        $sale_id = $existingSaleId;
    } else {
        // Insert new finalized sale record
        $ins = $conn->prepare("INSERT INTO finalized_sales (
                verification_id, property_id, agent_id, buyer_name, buyer_email, buyer_contact,
                final_sale_price, sale_date, commission_amount, commission_percentage, additional_notes,
                finalized_by, finalized_at, is_locked
            ) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $ins->bind_param(
            'iisssddsdsis',
            $property_id,
            $agent_id,
            $buyer_name,
            $buyer_email,
            $buyer_contact,
            $final_sale_price,
            $now,
            $commission_amount,
            $commission_percentage,
            $notes,
            $_SESSION['account_id'],
            $now
        );
        if (!$ins->execute()) {
            throw new Exception('Failed to insert finalized sale.');
        }
        $sale_id = $ins->insert_id;
        $ins->close();
    }

    // Upsert agent_commissions for this sale
    $commId = null;
    $cchk = $conn->prepare("SELECT commission_id FROM agent_commissions WHERE sale_id = ? LIMIT 1");
    $cchk->bind_param('i', $sale_id);
    $cchk->execute();
    $cres = $cchk->get_result();
    if ($cr = $cres->fetch_assoc()) {
        $commId = (int)$cr['commission_id'];
    }
    $cchk->close();

    if ($commId) {
        $cupd = $conn->prepare("UPDATE agent_commissions SET agent_id = ?, commission_amount = ?, commission_percentage = ?, status = 'calculated', calculated_at = ?, notes = ? WHERE commission_id = ?");
        $cupd->bind_param('iddssi', $agent_id, $commission_amount, $commission_percentage, $now, $notes, $commId);
        if (!$cupd->execute()) {
            throw new Exception('Failed to update commission.');
        }
        $cupd->close();
    } else {
        $cins = $conn->prepare("INSERT INTO agent_commissions (sale_id, agent_id, commission_amount, commission_percentage, status, calculated_at, notes, processed_by, created_at) VALUES (?, ?, ?, ?, 'calculated', ?, ?, ?, ?)");
        $cins->bind_param('iiddssis', $sale_id, $agent_id, $commission_amount, $commission_percentage, $now, $notes, $_SESSION['account_id'], $now);
        if (!$cins->execute()) {
            throw new Exception('Failed to create commission.');
        }
        $cins->close();
    }

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Sale finalized and commission calculated.',
        'sale_id' => $sale_id,
        'commission_amount' => $commission_amount
    ]);
    exit;
} catch (Exception $e) {
    if ($conn && $conn->errno === 0) {
        // If in transaction, rollback
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    exit;
}
