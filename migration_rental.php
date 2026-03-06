<?php
/**
 * Rental Feature Database Migration
 * Run once to create all required tables and alter existing ones.
 * DELETE THIS FILE AFTER RUNNING.
 */
require_once 'connection.php';

$errors = [];
$successes = [];

function runQuery($conn, $label, $sql) {
    global $errors, $successes;
    if ($conn->query($sql) === TRUE) {
        $successes[] = $label;
    } else {
        $errors[] = "$label: " . $conn->error;
    }
}

// 1. Add listing_type to property
runQuery($conn, 'Add listing_type column', "
    ALTER TABLE property 
    ADD COLUMN listing_type ENUM('For Sale','For Rent') NOT NULL DEFAULT 'For Sale' AFTER ParkingType
");

// 2. Update existing rental properties  
$conn->query("UPDATE property SET listing_type = 'For Rent' WHERE Status = 'For Rent'");
$updated = $conn->affected_rows;
$successes[] = "Updated $updated properties to 'For Rent'";

// 3. Create rental_verifications
runQuery($conn, 'Create rental_verifications', "
    CREATE TABLE IF NOT EXISTS rental_verifications (
        verification_id INT(11) NOT NULL AUTO_INCREMENT,
        property_id INT(11) NOT NULL,
        agent_id INT(11) NOT NULL,
        monthly_rent DECIMAL(12,2) NOT NULL,
        security_deposit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        lease_start_date DATE NOT NULL,
        lease_term_months INT(11) NOT NULL,
        tenant_name VARCHAR(255) NOT NULL,
        tenant_email VARCHAR(255) DEFAULT NULL,
        tenant_phone VARCHAR(20) DEFAULT NULL,
        additional_notes TEXT DEFAULT NULL,
        status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
        admin_notes TEXT DEFAULT NULL,
        reviewed_by INT(11) DEFAULT NULL,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (verification_id),
        KEY idx_rv_property_id (property_id),
        KEY idx_rv_agent_id (agent_id),
        KEY idx_rv_status (status),
        CONSTRAINT fk_rv_property FOREIGN KEY (property_id) REFERENCES property (property_ID) ON DELETE CASCADE,
        CONSTRAINT fk_rv_agent FOREIGN KEY (agent_id) REFERENCES accounts (account_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// 4. Create rental_verification_documents
runQuery($conn, 'Create rental_verification_documents', "
    CREATE TABLE IF NOT EXISTS rental_verification_documents (
        document_id INT(11) NOT NULL AUTO_INCREMENT,
        verification_id INT(11) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size INT(11) DEFAULT NULL,
        mime_type VARCHAR(100) DEFAULT NULL,
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (document_id),
        KEY idx_rvd_verification_id (verification_id),
        CONSTRAINT fk_rvd_verification FOREIGN KEY (verification_id) REFERENCES rental_verifications (verification_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// 5. Create finalized_rentals
runQuery($conn, 'Create finalized_rentals', "
    CREATE TABLE IF NOT EXISTS finalized_rentals (
        rental_id INT(11) NOT NULL AUTO_INCREMENT,
        verification_id INT(11) NOT NULL,
        property_id INT(11) NOT NULL,
        agent_id INT(11) NOT NULL,
        tenant_name VARCHAR(255) NOT NULL,
        tenant_email VARCHAR(255) DEFAULT NULL,
        tenant_phone VARCHAR(20) DEFAULT NULL,
        monthly_rent DECIMAL(12,2) NOT NULL,
        security_deposit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        lease_start_date DATE NOT NULL,
        lease_end_date DATE NOT NULL,
        lease_term_months INT(11) NOT NULL,
        commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Percentage per confirmed payment',
        additional_notes TEXT DEFAULT NULL,
        lease_status ENUM('Active','Renewed','Terminated','Expired') NOT NULL DEFAULT 'Active',
        terminated_at TIMESTAMP NULL DEFAULT NULL,
        terminated_by INT(11) DEFAULT NULL,
        termination_reason TEXT DEFAULT NULL,
        finalized_by INT(11) NOT NULL,
        finalized_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (rental_id),
        UNIQUE KEY uq_fr_verification (verification_id),
        KEY idx_fr_property_id (property_id),
        KEY idx_fr_agent_id (agent_id),
        KEY idx_fr_lease_status (lease_status),
        KEY idx_fr_lease_end_date (lease_end_date),
        CONSTRAINT fk_fr_verification FOREIGN KEY (verification_id) REFERENCES rental_verifications (verification_id) ON DELETE CASCADE,
        CONSTRAINT fk_fr_property FOREIGN KEY (property_id) REFERENCES property (property_ID) ON DELETE CASCADE,
        CONSTRAINT fk_fr_agent FOREIGN KEY (agent_id) REFERENCES accounts (account_id) ON DELETE CASCADE,
        CONSTRAINT fk_fr_finalized_by FOREIGN KEY (finalized_by) REFERENCES accounts (account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// 6. Create rental_payments
runQuery($conn, 'Create rental_payments', "
    CREATE TABLE IF NOT EXISTS rental_payments (
        payment_id INT(11) NOT NULL AUTO_INCREMENT,
        rental_id INT(11) NOT NULL COMMENT 'FK to finalized_rentals',
        agent_id INT(11) NOT NULL,
        payment_amount DECIMAL(12,2) NOT NULL,
        payment_date DATE NOT NULL,
        period_start DATE NOT NULL COMMENT 'Rental period start',
        period_end DATE NOT NULL COMMENT 'Rental period end',
        additional_notes TEXT DEFAULT NULL,
        status ENUM('Pending','Confirmed','Rejected') NOT NULL DEFAULT 'Pending',
        admin_notes TEXT DEFAULT NULL,
        confirmed_by INT(11) DEFAULT NULL,
        confirmed_at TIMESTAMP NULL DEFAULT NULL,
        submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (payment_id),
        KEY idx_rp_rental_id (rental_id),
        KEY idx_rp_agent_id (agent_id),
        KEY idx_rp_status (status),
        KEY idx_rp_period (period_start, period_end),
        CONSTRAINT fk_rp_rental FOREIGN KEY (rental_id) REFERENCES finalized_rentals (rental_id) ON DELETE CASCADE,
        CONSTRAINT fk_rp_agent FOREIGN KEY (agent_id) REFERENCES accounts (account_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// 7. Create rental_payment_documents
runQuery($conn, 'Create rental_payment_documents', "
    CREATE TABLE IF NOT EXISTS rental_payment_documents (
        document_id INT(11) NOT NULL AUTO_INCREMENT,
        payment_id INT(11) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size INT(11) DEFAULT NULL,
        mime_type VARCHAR(100) DEFAULT NULL,
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (document_id),
        KEY idx_rpd_payment_id (payment_id),
        CONSTRAINT fk_rpd_payment FOREIGN KEY (payment_id) REFERENCES rental_payments (payment_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// 8. Create rental_commissions
runQuery($conn, 'Create rental_commissions', "
    CREATE TABLE IF NOT EXISTS rental_commissions (
        commission_id INT(11) NOT NULL AUTO_INCREMENT,
        rental_id INT(11) NOT NULL,
        payment_id INT(11) NOT NULL,
        agent_id INT(11) NOT NULL,
        commission_amount DECIMAL(12,2) NOT NULL,
        commission_percentage DECIMAL(5,2) NOT NULL,
        status ENUM('pending','calculated','paid','cancelled') NOT NULL DEFAULT 'pending',
        calculated_at TIMESTAMP NULL DEFAULT NULL,
        paid_at TIMESTAMP NULL DEFAULT NULL,
        payment_reference VARCHAR(100) DEFAULT NULL,
        processed_by INT(11) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (commission_id),
        KEY idx_rc_rental_id (rental_id),
        KEY idx_rc_payment_id (payment_id),
        KEY idx_rc_agent_id (agent_id),
        CONSTRAINT fk_rc_rental FOREIGN KEY (rental_id) REFERENCES finalized_rentals (rental_id) ON DELETE CASCADE,
        CONSTRAINT fk_rc_payment FOREIGN KEY (payment_id) REFERENCES rental_payments (payment_id) ON DELETE CASCADE,
        CONSTRAINT fk_rc_agent FOREIGN KEY (agent_id) REFERENCES accounts (account_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// 9. Alter property_log ENUM
runQuery($conn, 'ALTER property_log action ENUM', "
    ALTER TABLE property_log 
    MODIFY action ENUM('CREATED','UPDATED','DELETED','SOLD','REJECTED','RENTED','LEASE_RENEWED','LEASE_TERMINATED') NOT NULL DEFAULT 'CREATED'
");

// 10. Alter notifications item_type ENUM
runQuery($conn, 'ALTER notifications item_type ENUM', "
    ALTER TABLE notifications 
    MODIFY item_type ENUM('agent','property','property_sale','property_rental','rental_payment','tour') NOT NULL DEFAULT 'agent'
");

// 11. Alter agent_notifications notif_type ENUM
runQuery($conn, 'ALTER agent_notifications notif_type ENUM', "
    ALTER TABLE agent_notifications 
    MODIFY notif_type ENUM('tour_new','tour_cancelled','tour_completed','property_approved','property_rejected','sale_approved','sale_rejected','commission_paid','rental_approved','rental_rejected','rental_payment_confirmed','rental_payment_rejected','rental_commission_paid','lease_expiring','lease_expired','general') NOT NULL DEFAULT 'general'
");

// 12. Alter price_history event_type ENUM
runQuery($conn, 'ALTER price_history event_type ENUM', "
    ALTER TABLE price_history 
    MODIFY event_type ENUM('Listed','Price Change','Sold','Off Market','Rented','Lease Ended') NOT NULL
");

// Output results
echo "=== Migration Results ===\n\n";
echo "Successes (" . count($successes) . "):\n";
foreach ($successes as $s) {
    echo "  [OK] $s\n";
}
if (!empty($errors)) {
    echo "\nErrors (" . count($errors) . "):\n";
    foreach ($errors as $e) {
        echo "  [ERR] $e\n";
    }
}

echo "\n=== Migration Complete ===\n";
$conn->close();
